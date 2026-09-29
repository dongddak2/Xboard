<?php
// ManduVPN RC regression: POST /api/v1/vpn/sessions (stage C session issuance). ONE rolled-back transaction.
//
// Proves the endpoint is FAIL-CLOSED and, above all, that IT NEVER REFUSES WITH 200. That is the point of this
// suite: the app reads `status` with `?? 'ACTIVE'` (vpn_session.dart:48), so a refusal sent as 200 with no
// status field would be read as a GRANTED session and the app would connect. A 200 refusal is the most
// dangerous failure this endpoint can have, so every refusal path below asserts the status code explicitly.
//
// WHY EVERY REFUSAL ASSERTION ALSO CHECKS THE `error` IDENTIFIER. On pre-change code this route does not
// exist, so Laravel answers 404 for every request here. An assertion that only checked "not 200" would
// therefore PASS on the unfixed code and prove nothing - a gate you only ever watched succeed is not a gate.
// Our refusals carry SERVER_NOT_ALLOWED / SUBSCRIPTION_EXPIRED / PROTOCOL_NOT_ALLOWED in `error`
// (ApiResponse::fail third argument); a route-miss 404 carries no `error` key at all. That field is what makes
// these assertions fail before the change and pass after.
//
// WHY THOSE IDENTIFIER NAMES AND NOT VPN_SESSION_*. The app maps identifiers through
// VpnErrorCode.fromServerCode (vpn_error.dart:22), a fixed vocabulary; an unrecognised name becomes
// VpnErrorCode.unknown, i.e. "unknown error" on screen. The names are part of the contract.
//
// ★ WHICH RESULTS ARE SQLITE-ONLY, AND THE RULE THAT DECIDES IT. The test panel DB is SQLite
// (RC_CONTRACTS.md §1.2-c) and whereJsonContains compiles to engine-specific SQL - json_each on SQLite,
// JSON_CONTAINS on MySQL. Marked labels are "passed ON SQLITE" and say nothing about MySQL; do not shorten
// that to "passed".
//
// ★THE RULE, AND WHY IT IS THIS RULE: the mark is a RE-RUN LIST - it names the results that have to be measured
// again once the production engine is fixed. So the question is not "is this reached through the match", which
// is only a mechanical proxy, but "would this result change if the engine-split predicate behaved differently".
// Over-marking costs one extra re-run; a missing mark is a silent defect on MySQL, so when in doubt, mark it.★
// Applied here: a grant traverses the match to succeed, so it is marked, and so is a refusal that had to FIND
// the node before another predicate rejected it. A refusal decided before the query (subscription, protocol,
// routingMode, auth, validation) or by the absence of any row is NOT marked - no engine can change its answer.
//
// MARKED: S1-S17 and S21.  S1/S12 are grants; S2-S11 ride on S1's 200 and share its fate; S13-S17 and S21
// are refusals reached through the match. NOT MARKED: S18 (no such row at all), S19/S20/S25 (refused before
// the query), S22-S24 (auth and validation).
//
// The risk is one-directional and that matters more than the count: if the match behaves differently on
// MySQL, the refusals still refuse, and what breaks is issuance (S1, S12) plus the body assertions carried on
// it (S2-S11).
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_vpn_session_rc.php
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

$uri = $route('Vpn\VpnSessionController@create');
t_check('setup: vpn session route registered', $uri !== null, (string) $uri);
// Fall back to the literal contract path so the requests below still execute on pre-change code instead of
// aborting the run. The app builds exactly this path from baseUrl + '/vpn/sessions'.
$URI = $uri ?? '/api/v1/vpn/sessions';
t_check('setup: route path is the one the app calls (baseUrl + /vpn/sessions)',
    $URI === '/api/v1/vpn/sessions', $URI);

$hasTable = DB::getSchemaBuilder()->hasTable('v2_vpn_session');
t_check('setup: v2_vpn_session table exists (migration applied)', $hasTable);

// Nothing outside the rolled-back transaction may change.
$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$serverBefore = $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']);
$userBefore = $sig('v2_user', ['id', 'uuid', 'token', 'group_id']);
$serverCountBefore = DB::table('v2_server')->count();
$userCountBefore = DB::table('v2_user')->count();
$sessionCountBefore = $hasTable ? DB::table('v2_vpn_session')->count() : 0;

// Set only when the assertion block runs to its end. Z4 reports it, so "stopped early" can never be read as
// "everything passed" - see the catch below.
$completed = false;

DB::beginTransaction();
try {
    $req = function (array $body, $auth = null) use ($http, $URI) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $URI, 'POST', [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->headers->get('Cache-Control')];
    };
    // A refusal is OURS only if it carries our identifier AND a 4xx status. See the header note.
    $refused = function ($label, array $body, $auth, $expectStatus, $expectError) use ($req) {
        [$st, $j] = $req($body, $auth);
        t_check($label, $st === $expectStatus && ($j['error'] ?? null) === $expectError,
            "http=$st error=" . json_encode($j['error'] ?? null));
        // The whole reason this endpoint is dangerous: a refusal must never be 200, whatever else is wrong.
        t_check("$label -- and it is not a 200 (a 200 refusal reads as ACTIVE in the app)", $st !== 200, "http=$st");
        return $j;
    };

    $mkUser = function (int $groupId) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-sess-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'group_id' => $groupId, 'plan_id' => null,
            'transfer_enable' => 1000000, 'expired_at' => null, 'banned' => 0,
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    // KEY ORDER MATTERS. Server::setProtocolSettingsAttribute picks its schema from $this->type, so `type`
    // must be filled BEFORE `protocol_settings` or the hysteria schema is not applied.
    // THE COLUMN LIST BELOW MATCHES test_hysteria2_profile_rc.php's $mkServer, KEY FOR KEY. Two fixture bugs
    // in two runs (`rate` missing, then ['auth_data'] missing) were both deviations from that sibling, each
    // found one HTTP call at a time, and each cost a full round-trip on a host with no way to run the suite.
    // So the whole skeleton was diffed against the sibling instead of patching the reported line, and every
    // column the sibling sets is now set here - including `code`, `route_ids`, `tags` and `sort`, which this
    // endpoint never reads. That is the point: a fixture's field list is dictated by the TABLE, not by what
    // the code under test happens to read. `rate` is decimal(8,2) with neither nullable nor a default
    // (2025_01_05_131425_create_v2_server_table.php:23), which is why omitting it aborted the insert before
    // any route code ran.
    //
    // DELIBERATE DIFFERENCES FROM THE SIBLING, each with its reason - anything not listed here is a mistake:
    //  - Signature takes $type explicitly instead of the sibling's ($attrs, $settings). This endpoint is
    //    multi-protocol (it selects the node type FROM the requested protocol), so type varies per fixture,
    //    while the profile endpoint it was copied from only ever builds hysteria nodes.
    //  - No protocol_settings defaults. The sibling defaults version/bandwidth/obfs/tls because it asserts on
    //    those fields; here the settings are supplied per fixture so a Hysteria 1 node can be built.
    //  - $req posts a JSON body and sets CONTENT_TYPE (the sibling GETs a query string): this route is POST.
    //  - REMOTE_ADDR uses 10.253.x rather than the sibling's 10.252.x, so the two suites' requests stay
    //    distinguishable in any log that records them.
    //  - $refused takes the expected status and identifier as arguments instead of hardcoding 404 /
    //    HY2_SERVER_NOT_ELIGIBLE: this endpoint refuses with three identifiers across 403 and 422.
    //  - The catch / Z4 pair has no sibling equivalent; it was added after an aborted run exited 0.
    $mkServer = function (string $type, array $groupIds, array $settings = [], bool $show = true, $parentId = null) {
        $s = new Server();
        $s->forceFill(['type' => $type])->forceFill([
            'code' => 'rbs-' . Str::lower(Str::random(12)),
            'name' => 'rb-sess-' . Str::random(6), 'rate' => 1,
            'host' => '198.51.100.' . random_int(1, 254), 'port' => '443', 'server_port' => 443,
            'group_ids' => $groupIds, 'route_ids' => [], 'tags' => [],
            'show' => $show, 'sort' => 999, 'parent_id' => $parentId,
            'protocol_settings' => $settings,
        ])->save();
        return $s;
    };

    $group = 900 + random_int(1, 90);
    $user = $mkUser($group);
    // generateAuthData() RETURNS AN ARRAY, and the bearer string is its 'auth_data' key. Passing the array
    // itself to HTTP_AUTHORIZATION reaches Symfony ServerBag::getHeaders, whose stripos() throws
    // "TypeError: array given". All 28 call sites across the 21 sibling suites use ['auth_data'].
    // The user is re-read with User::find for the same reason the siblings do it - see the skeleton note at
    // the top of the fixture block.
    $auth = (new AuthService(User::find($user->id)))->generateAuthData()['auth_data'];
    $other = $mkUser($group + 1);

    $hy2 = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2, 'tls' => ['server_name' => 'sess.example.invalid']]);
    $ikev2 = $mkServer(Server::TYPE_IKEV2, [$group]);
    $hy1 = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 1]);
    $hidden = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2], false);
    $child = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2], true, $hy2->id);
    $foreign = $mkServer(Server::TYPE_HYSTERIA, [$group + 500], ['version' => 2]);

    // PRECONDITION, NOT AN ASSERTION (HY2-CRED-1). A hysteria2 session is only issued to a caller who already
    // holds a live short-lived credential, and the credential is issued by the PROFILE read - so a suite that
    // creates a session without fetching a profile first is asking for something no real client asks for.
    // THE FIXTURE WALKS THE CLIENT'S OWN ORDER rather than reaching into the service: home_page:396 fetches the
    // profile, then :408 connect -> vpn_connection_manager:117 requests the session. Nothing here is asserted -
    // whether issuance works is test_vpn_hy2_credential_rc.php's job. $profileGet exists only for this step;
    // every other request in this suite is the POST that $req makes.
    $profileGet = function (int $serverId) use ($http, $route, $auth) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $uri = ($route('User\Hysteria2ProfileController@profile') ?? '/api/v1/user/server/hysteria2/profile')
            . '?server_id=' . $serverId;
        $r = Request::create('http://localhost' . $uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $auth,
            'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)]);
        return $http->handle($r)->getStatusCode();
    };
    $profileGet((int) $hy2->id);

    $base = ['deviceId' => 'synthetic-device-0001', 'protocol' => 'hysteria2', 'routingMode' => 'FULL_TUNNEL'];

    // --- the happy path, and the four app-dictated shape rules ---
    [$st1, $j1, $cc] = $req($base + ['serverId' => (string) $hy2->id], $auth);
    $d = $j1['data'] ?? [];
    t_check('S1 an eligible hysteria2 node yields a session (on SQLite)', $st1 === 200, "http=$st1 " . json_encode($j1['error'] ?? null));
    t_check('S2 sessionId is present and a non-empty string (the app non-null casts it) (on SQLite)',
        isset($d['sessionId']) && is_string($d['sessionId']) && $d['sessionId'] !== '', json_encode($d['sessionId'] ?? null));
    t_check('S3 serverId comes back as a STRING, not an int (app parses it as String?) (on SQLite)',
        array_key_exists('serverId', $d) && $d['serverId'] === (string) $hy2->id,
        var_export($d['serverId'] ?? null, true));
    t_check('S4 status is ACTIVE explicitly, not left to the app default (on SQLite)',
        ($d['status'] ?? null) === 'ACTIVE', json_encode($d['status'] ?? null));
    t_check('S5 connectionConfig key is ABSENT (it would overwrite the verified profile credential) (on SQLite)',
        !array_key_exists('connectionConfig', $d), json_encode(array_keys($d)));
    t_check('S6 connectedAt parses as a date (app uses DateTime.tryParse) (on SQLite)',
        isset($d['connectedAt']) && strtotime((string) $d['connectedAt']) !== false, json_encode($d['connectedAt'] ?? null));
    t_check('S7 deviceId and routingMode are echoed back unchanged (on SQLite)',
        ($d['deviceId'] ?? null) === 'synthetic-device-0001' && ($d['routingMode'] ?? null) === 'FULL_TUNNEL',
        json_encode([$d['deviceId'] ?? null, $d['routingMode'] ?? null]));
    t_check('S8 response is not cacheable (Cache-Control: no-store) (on SQLite)', str_contains((string) $cc, 'no-store'), (string) $cc);
    t_check('S9 no credential value appears anywhere in the body (on SQLite)',
        !str_contains(json_encode($j1), (string) $user->uuid) && !str_contains(json_encode($j1), (string) $user->token));

    // --- the row was actually written, and it records what was issued ---
    if ($hasTable) {
        $row = DB::table('v2_vpn_session')->where('session_id', $d['sessionId'] ?? '')->first();
        t_check('S10 a session row was written for this user and node (on SQLite)',
            $row !== null && (int) $row->user_id === (int) $user->id && (int) $row->server_id === (int) $hy2->id
            && $row->status === 'ACTIVE' && $row->protocol === 'hysteria2',
            $row === null ? 'no row' : json_encode(['u' => $row->user_id, 's' => $row->server_id, 'st' => $row->status]));
        t_check('S11 last_heartbeat_at starts null - create never writes it, only heartbeat does (on SQLite)',
            $row !== null && $row->last_heartbeat_at === null, $row === null ? 'no row' : var_export($row->last_heartbeat_at, true));
    } else {
        t_check('S10 a session row was written for this user and node (on SQLite)', false, 'table missing');
        t_check('S11 last_heartbeat_at starts null - create never writes it, only heartbeat does (on SQLite)', false, 'table missing');
    }

    // --- an ikev2 node is issuable too, and the protocol selects the node type ---
    [$stI, $jI] = $req(['deviceId' => 'synthetic-device-0002', 'protocol' => 'ikev2', 'serverId' => (string) $ikev2->id], $auth);
    t_check('S12 an eligible ikev2 node yields a session and routingMode defaults to FULL_TUNNEL (on SQLite)',
        $stI === 200 && (($jI['data']['routingMode'] ?? null) === 'FULL_TUNNEL'),
        "http=$stI " . json_encode($jI['data']['routingMode'] ?? ($jI['error'] ?? null)));

    // --- REFUSALS. Each asserts the identifier AND that it is not a 200. ---
    $refused('S13 asking for a hysteria node with protocol=ikev2 is refused - type must match (on SQLite)',
        ['deviceId' => 'd', 'protocol' => 'ikev2', 'serverId' => (string) $hy2->id], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S14 a Hysteria 1 node is refused - the profile endpoint could not serve it either (on SQLite)',
        $base + ['serverId' => (string) $hy1->id], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S15 a hidden node (show=0) is refused (on SQLite)',
        $base + ['serverId' => (string) $hidden->id], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S16 a child node (parent_id set) is refused (on SQLite)',
        $base + ['serverId' => (string) $child->id], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S17 a node in another group is refused (on SQLite)',
        $base + ['serverId' => (string) $foreign->id], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S18 an unknown server_id is refused with the same identifier (no node enumeration)',
        $base + ['serverId' => '2147483600'], $auth, 403, 'SERVER_NOT_ALLOWED');
    $refused('S19 an unsupported protocol (vmess) is refused with PROTOCOL_NOT_ALLOWED',
        ['deviceId' => 'd', 'protocol' => 'vmess', 'serverId' => (string) $hy2->id], $auth, 403, 'PROTOCOL_NOT_ALLOWED');
    $refused('S20 an unknown routingMode is refused, not silently downgraded to FULL_TUNNEL',
        ['deviceId' => 'd', 'protocol' => 'hysteria2', 'routingMode' => 'TURBO', 'serverId' => (string) $hy2->id],
        $auth, 422, 'PROTOCOL_NOT_ALLOWED');

    // --- another account cannot obtain a session for a node it may not use (on SQLite) ---
    // Same two corrections as the first token. This line had the identical defect and had simply not been
    // reached yet when the run aborted earlier - an unreached line is not a working line.
    $otherAuth = (new AuthService(User::find($other->id)))->generateAuthData()['auth_data'];
    $refused('S21 a different account is refused for this group node (identity comes from the token only) (on SQLite)',
        $base + ['serverId' => (string) $hy2->id], $otherAuth, 403, 'SERVER_NOT_ALLOWED');

    // --- auth and input validation ---
    [$stA] = $req($base + ['serverId' => (string) $hy2->id], null);
    t_check('S22 unauthenticated request is refused with 403', $stA === 403, "http=$stA");
    [$stV] = $req(['protocol' => 'hysteria2', 'serverId' => (string) $hy2->id], $auth);
    t_check('S23 missing deviceId is a validation error, not a session', $stV === 422, "http=$stV");
    [$stV2] = $req(['deviceId' => 'd', 'protocol' => 'hysteria2'], $auth);
    t_check('S24 missing serverId is a validation error, not a session', $stV2 === 422, "http=$stV2");

    // --- an inactive subscription gets no session ---
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => time() - 3600]);
    $refused('S25 expired subscription is refused with SUBSCRIPTION_EXPIRED',
        $base + ['serverId' => (string) $hy2->id], $auth, 403, 'SUBSCRIPTION_EXPIRED');

    $completed = true;
} catch (\Throwable $e) {
    // AN ABORT IS A FAILURE, NOT A QUIET STOP. Without this catch an exception left the script through
    // Laravel's global handler, which printed a trace but did NOT make the suite report failure: the first run
    // of this file died on a fixture insert after 3 of its assertions and the shell still saw exit=0. A gate
    // that exits 0 when it never reached its own verdict is worse than no gate, because a caller that only
    // checks the exit code reads the crash as a pass. The value is counted as a failure here and Z4 below says
    // plainly that the run did not finish.
    $fails++;
    echo 'FAIL suite aborted by an exception -- ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

t_check('Z1 v2_server unchanged (fixtures rolled back)', $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']) === $serverBefore);
t_check('Z2 v2_user unchanged', $sig('v2_user', ['id', 'uuid', 'token', 'group_id']) === $userBefore);
t_check('Z3 row counts unchanged, INCLUDING the sessions this suite issued',
    DB::table('v2_server')->count() === $serverCountBefore
    && DB::table('v2_user')->count() === $userCountBefore
    && (!$hasTable || DB::table('v2_vpn_session')->count() === $sessionCountBefore),
    $hasTable ? ('sessions=' . DB::table('v2_vpn_session')->count() . ' before=' . $sessionCountBefore) : 'table missing');
t_check('Z4 the suite reached the end of its assertion block (a partial run is not a pass)', $completed);

echo ($fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n");
exit($fails === 0 ? 0 : 1);
