<?php
// ManduVPN RC regression: GET /api/v1/vpn/entitlements (stage C, BUILD-FIRST 2026-09-29).
// ONE rolled-back transaction. Siblings: test_vpn_session_rc.php, test_vpn_session_lifecycle_rc.php.
//
// ★ WHAT THIS SUITE GUARDS AGAINST: OVERSTATEMENT, not omission. Every field of the app's Entitlement model is
// optional (entitlement.dart:26 - `int? ?? 1`, `?? const []`), so a missing field can only understate. The
// damage comes the other way: if this endpoint reports a protocol the session route would refuse, the app
// offers the user a connection that then fails. So most assertions below are of the form "an ineligible node
// does NOT appear", and every such node is one the session route also refuses.
//
// WHY A HINT ENDPOINT IS WORTH A SUITE AT ALL. The app treats the answer as display-only and re-verifies at
// POST /vpn/sessions, and EntitlementApi is currently instantiated NOWHERE (zero references). What is protected
// here is the CONTRACT, not today's runtime: the app's fail-open defect in this very place was removed on
// 2026-09-27 (it used to substitute `fallbackIkev2Only`, showing an "IKEv2, 1 device" nobody had granted), and
// this suite is what stops the server growing its own version of that lie.
//
// ★ WHICH RESULTS ARE SQLITE-ONLY, AND THE RULE THAT DECIDES IT. The test panel DB is SQLite
// (RC_CONTRACTS.md §1.2-c) and whereJsonContains compiles per engine - json_each on SQLite, JSON_CONTAINS on
// MySQL (here: VpnEntitlementController:115).
//
// ★THE MARK IS A RE-RUN LIST: it names the results that have to be measured again once the production engine is
// fixed. So the test is not "does this go through the match", which is only a mechanical proxy, but "would this
// result change if the predicate behaved differently". Over-marking costs one extra re-run; a missing mark is a
// silent defect on MySQL, so when in doubt, mark it.★
//
// allowedProtocols is computed THROUGH that match, so every assertion about its contents is marked.
// NOT MARKED: deviceLimit, subscriptionExpiresAt,
// allowedServerGroups, the no-store header, and the refusals decided before any node query (auth, lapsed
// subscription, banned) - none of those touch a JSON predicate.
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_vpn_entitlements_rc.php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Server, User};
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };

$uri = $route('Vpn\VpnEntitlementController@show');
t_check('setup: entitlements route registered', $uri !== null, (string) $uri);
// The app builds exactly this path from baseUrl + '/vpn/entitlements' (entitlement_api.dart:27). Asserted
// separately from "registered" so a route under the wrong path is a distinct failure from a missing route.
t_check('setup: route path is the one the app calls (baseUrl + /vpn/entitlements)',
    $uri === '/api/v1/vpn/entitlements', (string) $uri);
$URI = $uri ?? '/api/v1/vpn/entitlements';

// Nothing outside the rolled-back transaction may change. This endpoint writes nothing, so Z1-Z3 double as the
// assertion that it is a pure read.
$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$serverBefore = $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']);
$userBefore = $sig('v2_user', ['id', 'uuid', 'token', 'group_id']);
$serverCountBefore = DB::table('v2_server')->count();
$userCountBefore = DB::table('v2_user')->count();

// Set only when the assertion block runs to its end - see the catch below.
$completed = false;

DB::beginTransaction();
try {
    // DELIBERATE DIFFERENCES FROM THE SIBLINGS' SKELETON, each with its reason - anything not listed is a bug:
    //  - $req GETs with a query string instead of posting a JSON body: this route is a read. The query string
    //    exists only so E14 can prove a user_id parameter is ignored.
    //  - REMOTE_ADDR uses 10.255.x (session suite: 10.253.x, lifecycle: 10.254.x, profile: 10.252.x).
    //  - No $refused / $revoked helper: there are two refusal paths and each is asserted inline with its own
    //    second check, so a shared helper would hide more than it saves.
    //  - $mkUser / $mkServer column lists are the siblings' KEY FOR KEY. A fixture's field list is dictated by
    //    the TABLE's NOT NULL columns, not by what the code under test reads - `rate` is the scar that taught it.
    $req = function (string $query = '', $auth = null) use ($http, $URI) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.255.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $URI . $query, 'GET', [], [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->headers->get('Cache-Control')];
    };

    $mkUser = function (int $groupId) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-ent-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'group_id' => $groupId, 'plan_id' => null,
            'transfer_enable' => 1000000, 'expired_at' => null, 'banned' => 0,
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    // KEY ORDER MATTERS. Server::setProtocolSettingsAttribute picks its schema from $this->type, so `type` must
    // be filled BEFORE `protocol_settings` or the hysteria schema is not applied.
    $mkServer = function (string $type, array $groupIds, array $settings = [], bool $show = true, $parentId = null) {
        $s = new Server();
        $s->forceFill(['type' => $type])->forceFill([
            'code' => 'rbe-' . Str::lower(Str::random(12)),
            'name' => 'rb-ent-' . Str::random(6), 'rate' => 1,
            'host' => '198.51.100.' . random_int(1, 254), 'port' => '443', 'server_port' => 443,
            'group_ids' => $groupIds, 'route_ids' => [], 'tags' => [],
            'show' => $show, 'sort' => 999, 'parent_id' => $parentId,
            'protocol_settings' => $settings,
        ])->save();
        return $s;
    };

    // THREE GROUPS, because the interesting assertions are about what a group does NOT get:
    //   $group     one eligible Hysteria 2 node + one eligible IKEv2 node
    //   $groupHy1  only ineligible hysteria nodes (version 1, hidden, child)
    //   $groupNone no nodes at all
    $group = 700 + random_int(1, 30);
    $groupHy1 = $group + 100;
    $groupNone = $group + 200;

    $user = $mkUser($group);
    // generateAuthData() RETURNS AN ARRAY and the bearer string is its 'auth_data' key; passing the array itself
    // reaches Symfony ServerBag::getHeaders and throws "TypeError: array given".
    $auth = (new AuthService(User::find($user->id)))->generateAuthData()['auth_data'];
    $userHy1 = $mkUser($groupHy1);
    $authHy1 = (new AuthService(User::find($userHy1->id)))->generateAuthData()['auth_data'];
    $userNone = $mkUser($groupNone);
    $authNone = (new AuthService(User::find($userNone->id)))->generateAuthData()['auth_data'];

    $hy2 = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2, 'tls' => ['server_name' => 'ent.example.invalid']]);
    $mkServer(Server::TYPE_IKEV2, [$group]);
    // Every node $groupHy1 can see is one the session route would refuse.
    $mkServer(Server::TYPE_HYSTERIA, [$groupHy1], ['version' => 1]);
    $mkServer(Server::TYPE_HYSTERIA, [$groupHy1], ['version' => 2], false);
    $mkServer(Server::TYPE_HYSTERIA, [$groupHy1], ['version' => 2], true, $hy2->id);

    // --- the granted path ---
    [$st1, $j1, $cc] = $req('', $auth);
    $d = $j1['data'] ?? [];
    t_check('E1 an active account gets its entitlements (on SQLite)', $st1 === 200, "http=$st1 " . json_encode($j1['error'] ?? null));
    t_check('E2 allowedProtocols lists both protocols this group has a connectable node for (on SQLite)',
        is_array($d['allowedProtocols'] ?? null) && count($d['allowedProtocols']) === 2
        && in_array('hysteria2', $d['allowedProtocols'], true) && in_array('ikev2', $d['allowedProtocols'], true),
        json_encode($d['allowedProtocols'] ?? null));
    t_check('E3 allowedProtocols encodes as a JSON ARRAY, not an object (the app casts it with `as List?`) (on SQLite)',
        str_starts_with(json_encode($d['allowedProtocols'] ?? null), '['), json_encode($d['allowedProtocols'] ?? null));
    t_check('E4 the protocol names are the app\'s own wire values, so VpnProtocol.fromWire does not drop them (on SQLite)',
        array_diff($d['allowedProtocols'] ?? [], ['ikev2', 'hysteria2', 'vless', 'vmess']) === [],
        json_encode($d['allowedProtocols'] ?? null));
    t_check('E5 allowedServerGroups carries the group id AS A STRING (the app casts the list to String)',
        ($d['allowedServerGroups'] ?? null) === [(string) $group], json_encode($d['allowedServerGroups'] ?? null));
    t_check('E6 subscriptionExpiresAt is null when the account never expires, not a 1970 date',
        array_key_exists('subscriptionExpiresAt', $d) && $d['subscriptionExpiresAt'] === null,
        json_encode($d['subscriptionExpiresAt'] ?? 'MISSING'));
    t_check('E7 deviceLimit is OMITTED while device_limit is null, so the app reads 1 (understating, not guessing)',
        !array_key_exists('deviceLimit', $d) && !array_key_exists('concurrentConnectionLimit', $d),
        json_encode(array_keys($d)));
    t_check('E8 response is not cacheable (Cache-Control: no-store)', str_contains((string) $cc, 'no-store'), (string) $cc);
    t_check('E9 no credential value appears anywhere in the body',
        !str_contains(json_encode($j1), (string) $user->uuid) && !str_contains(json_encode($j1), (string) $user->token));

    // --- the two numbers, once a limit exists ---
    DB::table('v2_user')->where('id', $user->id)->update(['device_limit' => 4]);
    [$st2, $j2] = $req('', $auth);
    $d2 = $j2['data'] ?? [];
    t_check('E10 deviceLimit and concurrentConnectionLimit are both ints and both carry the ONE limit this stack has',
        $st2 === 200 && ($d2['deviceLimit'] ?? null) === 4 && ($d2['concurrentConnectionLimit'] ?? null) === 4,
        json_encode([$d2['deviceLimit'] ?? null, $d2['concurrentConnectionLimit'] ?? null]));

    // --- a future expiry is reported as a parseable date (the app uses DateTime.tryParse) ---
    $future = time() + 86400 * 30;
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => $future]);
    [$st3, $j3] = $req('', $auth);
    t_check('E11 a future expiry is an ISO-8601 date the app can parse, and it is the stored instant',
        $st3 === 200 && strtotime((string) ($j3['data']['subscriptionExpiresAt'] ?? '')) === $future,
        json_encode($j3['data']['subscriptionExpiresAt'] ?? null));

    // --- OVERSTATEMENT GUARDS. Every node behind these is one the session route also refuses. ---
    [$st4, $j4] = $req('', $authHy1);
    t_check('E12 a group whose only nodes are Hysteria 1 / hidden / child gets an EMPTY protocol list (on SQLite)',
        $st4 === 200 && ($j4['data']['allowedProtocols'] ?? null) === [],
        "http=$st4 " . json_encode($j4['data']['allowedProtocols'] ?? null));
    [$st5, $j5] = $req('', $authNone);
    t_check('E13 a group with no nodes at all gets an empty list too, and still a 200 (on SQLite)',
        $st5 === 200 && ($j5['data']['allowedProtocols'] ?? null) === []
        && ($j5['data']['allowedServerGroups'] ?? null) === [(string) $groupNone],
        "http=$st5 " . json_encode($j5['data'] ?? null));

    // --- positive/negative authorization: identity comes from the token, never from a parameter ---
    [$st6, $j6] = $req('?user_id=' . (int) $user->id . '&email=' . urlencode((string) $user->email), $authNone);
    t_check('E14 a user_id parameter is ignored - the answer is still the CALLER\'s, not that id\'s (on SQLite)',
        $st6 === 200 && ($j6['data']['allowedServerGroups'] ?? null) === [(string) $groupNone]
        && ($j6['data']['allowedProtocols'] ?? null) === [],
        json_encode($j6['data'] ?? null));

    [$st7] = $req('', null);
    t_check('E15 unauthenticated request is refused with 403', $st7 === 403, "http=$st7");

    // --- a lapsed subscription is REFUSED, not described with empty lists ---
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => time() - 3600]);
    [$st8, $j8] = $req('', $auth);
    t_check('E16 an expired subscription is refused with 403 + SUBSCRIPTION_EXPIRED',
        $st8 === 403 && ($j8['error'] ?? null) === 'SUBSCRIPTION_EXPIRED', "http=$st8 error=" . json_encode($j8['error'] ?? null));
    t_check('E17 and that refusal carries NO entitlement data (an empty 200 would assert "no entitlements", a different claim)',
        $st8 !== 200 && ($j8['data'] ?? null) === null, json_encode($j8['data'] ?? 'MISSING'));

    // --- a banned account is refused by the same gate, so being banned cannot read as "no limits" ---
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => null, 'banned' => 1]);
    [$st9, $j9] = $req('', $auth);
    t_check('E18 a banned account is refused with the same identifier (isAvailable is the one gate)',
        $st9 === 403 && ($j9['error'] ?? null) === 'SUBSCRIPTION_EXPIRED', "http=$st9 error=" . json_encode($j9['error'] ?? null));

    $completed = true;
} catch (\Throwable $e) {
    // AN ABORT IS A FAILURE, NOT A QUIET STOP. Without this catch an exception leaves through Laravel's global
    // handler, which prints a trace but lets the shell see exit=0 - a gate that exits 0 without reaching its own
    // verdict is worse than no gate, because a caller that checks only the exit code reads the crash as a pass.
    $fails++;
    echo 'FAIL suite aborted by an exception -- ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

t_check('Z1 v2_server unchanged (fixtures rolled back)', $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']) === $serverBefore);
t_check('Z2 v2_user unchanged', $sig('v2_user', ['id', 'uuid', 'token', 'group_id']) === $userBefore);
t_check('Z3 row counts unchanged (this route is a pure read; only fixtures were created)',
    DB::table('v2_server')->count() === $serverCountBefore && DB::table('v2_user')->count() === $userCountBefore,
    'servers=' . DB::table('v2_server')->count() . ' users=' . DB::table('v2_user')->count());
t_check('Z4 the suite reached the end of its assertion block (a partial run is not a pass)', $completed);

echo ($fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n");
exit($fails === 0 ? 0 : 1);
