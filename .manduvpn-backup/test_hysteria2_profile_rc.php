<?php
// ManduVPN RC regression: the Hysteria2 connection profile endpoint. ONE rolled-back transaction.
//
// Proves the endpoint is FAIL-CLOSED: only a hysteria node with protocol_settings.version = 2, show = 1,
// parent_id NULL and the caller's group_id in group_ids yields a profile. Everything else is 404.
//
// ★ WHY EVERY 404 ASSERTION ALSO CHECKS THE `error` IDENTIFIER. On pre-change code this route does not
// exist, so Laravel answers 404 as well. A test that asserted only "status == 404" would therefore PASS on
// the unfixed code and prove nothing - a gate you only ever watched succeed is not a gate. Our refusal
// carries error = HY2_SERVER_NOT_ELIGIBLE (ApiResponse::fail's third argument); a route-miss 404 carries no
// `error` key at all. That one field is what makes these assertions fail before the change and pass after.
// Same shape as the CONSENT_REQUIRED identifier check in the register probe: never let a shared status code
// stand in for the thing you are actually testing.
//
// This script compiles and RUNS on pre-change code: the request URI is derived from the IKEv2 profile route
// (replacing ikev2 -> hysteria2), so it is a real path even when the new route is absent.
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_hysteria2_profile_rc.php
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

$ikev2Uri = $route('User\Ikev2ProfileController@profile');
$hy2Uri = $route('User\Hysteria2ProfileController@profile');
t_check('setup: IKEv2 profile route found (the shape this one copies)', $ikev2Uri !== null, (string) $ikev2Uri);
t_check('setup: hysteria2 profile route registered', $hy2Uri !== null, (string) $hy2Uri);
// Fall back to the IKEv2 path with the protocol swapped, so the requests below still execute on pre-change
// code instead of aborting the run.
$URI = $hy2Uri ?? ($ikev2Uri !== null ? str_replace('ikev2', 'hysteria2', $ikev2Uri) : '/api/v1/user/server/hysteria2/profile');

// Nothing outside the rolled-back transaction may change.
$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$serverBefore = $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']);
$userBefore = $sig('v2_user', ['id', 'uuid', 'token', 'group_id']);
$serverCountBefore = DB::table('v2_server')->count();
$userCountBefore = DB::table('v2_user')->count();

DB::beginTransaction();
try {
    $req = function (array $query, $auth = null) use ($http, $URI) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.252.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $URI, 'GET', $query, [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->headers->get('Cache-Control')];
    };
    // A refusal is OURS only if it carries our identifier. A bare 404 is not evidence - see the header note.
    $refused = function ($label, array $query, $auth) use ($req) {
        [$st, $j] = $req($query, $auth);
        t_check($label, $st === 404 && ($j['error'] ?? null) === 'HY2_SERVER_NOT_ELIGIBLE',
            "http=$st error=" . json_encode($j['error'] ?? null));
        return $j;
    };

    $mkUser = function (int $groupId) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-hy2-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'group_id' => $groupId, 'plan_id' => null,
            'transfer_enable' => 1000000, 'expired_at' => null, 'banned' => 0,
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    // KEY ORDER MATTERS HERE. Server::setProtocolSettingsAttribute picks its schema from $this->type, so
    // `type` must be filled BEFORE `protocol_settings`. $attrs comes first and `protocol_settings` is the
    // last default key, so both the overridden-type case (ikev2) and the default case are safe. Reordering
    // these two keys would silently cast the settings against the wrong protocol schema.
    $mkServer = function (array $attrs, array $settings = []) {
        $s = new Server();
        $s->forceFill($attrs + [
            'type' => Server::TYPE_HYSTERIA,
            'code' => 'rbk-' . Str::lower(Str::random(12)),
            'parent_id' => null, 'route_ids' => [], 'tags' => [],
            'name' => 'rollback-test-hy2', 'rate' => 1,
            'host' => 'hy2.example.invalid', 'port' => '4443', 'server_port' => 4443,
            'show' => true, 'sort' => 999,
            'protocol_settings' => $settings + [
                'version' => 2,
                'bandwidth' => ['up' => 100, 'down' => 200],
                'obfs' => ['open' => false, 'type' => 'salamander', 'password' => 'must-not-leak'],
                'tls' => ['server_name' => 'sni.example.invalid', 'allow_insecure' => false],
            ],
        ])->save();
        return $s;
    };

    $gid = 9101;
    $otherGid = 9102;
    $user = $mkUser($gid);
    $auth = (new AuthService(User::find($user->id)))->generateAuthData()['auth_data'];

    $ok = $mkServer(['group_ids' => [$gid]]);
    $hy1 = $mkServer(['group_ids' => [$gid], 'name' => 'rollback-test-hy1'], ['version' => 1]);
    $otherGroup = $mkServer(['group_ids' => [$otherGid]]);
    $hidden = $mkServer(['group_ids' => [$gid], 'show' => false]);
    $child = $mkServer(['group_ids' => [$gid], 'parent_id' => $ok->id]);
    $ikev2 = $mkServer(['group_ids' => [$gid], 'type' => Server::TYPE_IKEV2]);
    $ranged = $mkServer(['group_ids' => [$gid], 'port' => '5000-5002']);

    // --- the positive case: this is what must change from 404 to 200 ---
    [$st, $j, $cc] = $req(['server_id' => $ok->id], $auth);
    t_check('H1 eligible hysteria2 node returns 200', $st === 200, "http=$st");
    t_check('H1 schema is manduvpn.hysteria2.profile.v1', ($j['data']['schema'] ?? null) === 'manduvpn.hysteria2.profile.v1', json_encode($j['data']['schema'] ?? null));
    t_check('H1 host and port are the connection address', ($j['data']['server']['host'] ?? null) === 'hy2.example.invalid' && (int) ($j['data']['server']['port'] ?? 0) === 4443);
    t_check('H1 bandwidth is carried (the value the subscription URI drops)', ($j['data']['bandwidth']['up'] ?? null) === 100 && ($j['data']['bandwidth']['down'] ?? null) === 200, json_encode($j['data']['bandwidth'] ?? null));
    t_check('H1 tls.server_name is the SNI', ($j['data']['tls']['server_name'] ?? null) === 'sni.example.invalid');
    t_check('H1 auth is THIS caller\'s uuid', ($j['data']['credential']['auth'] ?? null) === (string) $user->uuid);
    t_check('H1 Cache-Control is no-store', is_string($cc) && str_contains($cc, 'no-store'), (string) $cc);
    // `??` fires on null, not only on a missing key, so `($x['password'] ?? 'x') === null` can never be
    // true - it yields 'x' in exactly the case this is meant to accept. The first version of this line was
    // written that way and was therefore unsatisfiable: it failed closed on a correct response. Presence and
    // null-ness are two separate questions here, so they get two separate checks.
    $obfs = $j['data']['obfs'] ?? null;
    t_check('H1 obfs password is withheld while obfs is off',
        is_array($obfs) && ($obfs['open'] ?? true) === false
        && array_key_exists('password', $obfs) && $obfs['password'] === null,
        json_encode($obfs));
    // The property itself, not a proxy for it: the fixture's obfs secret must appear nowhere in the body.
    // Previously mislabelled "carries no other user's uuid", which is NOT what it checks.
    t_check('H1 the obfs secret appears nowhere in the response body', !str_contains(json_encode($j), 'must-not-leak'));

    // --- the three refusals LEAD asked for, plus the neighbours that share the rule ---
    $refused('H2 version is not 2 -> 404 with our identifier', ['server_id' => $hy1->id], $auth);
    $refused('H3 group mismatch -> 404 with our identifier', ['server_id' => $otherGroup->id], $auth);
    $refused('H4 show = 0 (ineligible node) -> 404 with our identifier', ['server_id' => $hidden->id], $auth);
    $refused('H5 child node (parent_id set) -> 404 with our identifier', ['server_id' => $child->id], $auth);
    $refused('H6 ikev2 node through the hysteria2 endpoint -> 404 with our identifier', ['server_id' => $ikev2->id], $auth);
    $unknown = $refused('H7 unknown server_id -> 404 with our identifier', ['server_id' => DB::table('v2_server')->max('id') + 1000], $auth);
    // No enumeration: "you may not" and "it does not exist" must be indistinguishable.
    $ineligible = $refused('H8 ineligible node -> 404 with our identifier', ['server_id' => $otherGroup->id], $auth);
    t_check('H9 unknown and ineligible are indistinguishable (no node enumeration)',
        ($unknown['message'] ?? 'a') === ($ineligible['message'] ?? 'b') && ($unknown['error'] ?? 'a') === ($ineligible['error'] ?? 'b'));

    // --- version typing: what the accessor's cast does and does not protect against ---
    // A stored string "2" cannot be produced through Eloquent at all: setProtocolSettingsAttribute casts on
    // WRITE too. So these rows are written with raw SQL, which is the only way the defect can exist.
    $rawVersion = function ($value) use ($mkServer, $gid) {
        $s = $mkServer(['group_ids' => [$gid]]);
        $settings = json_decode(DB::table('v2_server')->where('id', $s->id)->value('protocol_settings'), true);
        $settings['version'] = $value;
        DB::table('v2_server')->where('id', $s->id)->update(['protocol_settings' => json_encode($settings)]);
        return $s->id;
    };
    // MEASURED, NOT ASSUMED: PROTOCOL_CONFIGURATIONS['hysteria']['version'] is type 'integer' and
    // castValueWithConfig does `(int) $value`, so the READ accessor turns "2" into int 2 before any
    // comparison sees it. The string therefore does NOT degrade the node to Hysteria 1 - not here, and not
    // in ClientController:161 / General:223 / Loon:212 / Surge:179 either, since all of them read through
    // the same accessor. Asserting 404 here would assert something the code does not do. What this test
    // pins down is that the cast is the thing doing the work, so nobody later "fixes" it with a loose
    // operator and believes they changed the outcome.
    [$stS2, $jS2] = $req(['server_id' => $rawVersion('2')], $auth);
    t_check('H14 raw string "2" is still served: the accessor casts it to int 2 before comparison',
        $stS2 === 200 && ($jS2['data']['schema'] ?? null) === 'manduvpn.hysteria2.profile.v1', "http=$stS2");
    // The cast is not a validator: a non-numeric string becomes 0, which is not 2, so it is refused.
    $refused('H15 raw string "abc" (casts to 0) -> 404 with our identifier', ['server_id' => $rawVersion('abc')], $auth);
    // "2abc" also casts to 2. Recorded because it is the one input that really does slip through, and the
    // fix for it is correcting the node record, not loosening or tightening this comparison.
    [$stS3] = $req(['server_id' => $rawVersion('2abc')], $auth);
    t_check('H16 raw string "2abc" also casts to 2 and is served - cast is not validation (POST-RC)', $stS3 === 200, "http=$stS3");

    // --- a port range must come back as port + ports, like the subscription's mport ---
    [$stR, $jR] = $req(['server_id' => $ranged->id], $auth);
    t_check('H10 port range yields ports = the range and port inside it',
        $stR === 200 && ($jR['data']['server']['ports'] ?? null) === '5000-5002'
        && (int) ($jR['data']['server']['port'] ?? 0) >= 5000 && (int) ($jR['data']['server']['port'] ?? 0) <= 5002,
        "http=$stR " . json_encode($jR['data']['server'] ?? null));

    // --- auth and input validation ---
    [$stA] = $req(['server_id' => $ok->id], null);
    t_check('H11 unauthenticated request is refused', $stA === 403, "http=$stA");
    [$stM] = $req([], $auth);
    t_check('H12 missing server_id is a validation error, not a profile', $stM === 422, "http=$stM");

    // --- an inactive subscription gets no credential ---
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => time() - 3600]);
    [$stE, $jE] = $req(['server_id' => $ok->id], $auth);
    t_check('H13 expired subscription -> 403 HY2_SUBSCRIPTION_INACTIVE',
        $stE === 403 && ($jE['error'] ?? null) === 'HY2_SUBSCRIPTION_INACTIVE', "http=$stE error=" . json_encode($jE['error'] ?? null));
} finally {
    DB::rollBack();
}

t_check('Z1 v2_server unchanged (read-only endpoint, fixtures rolled back)', $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']) === $serverBefore);
t_check('Z2 v2_user unchanged', $sig('v2_user', ['id', 'uuid', 'token', 'group_id']) === $userBefore);
t_check('Z3 row counts unchanged', DB::table('v2_server')->count() === $serverCountBefore && DB::table('v2_user')->count() === $userCountBefore);

echo ($fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n");
exit($fails === 0 ? 0 : 1);
