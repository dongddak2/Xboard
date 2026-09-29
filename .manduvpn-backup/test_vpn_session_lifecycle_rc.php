<?php
// ManduVPN RC regression: the session LIFECYCLE routes (stage C, BUILD-FIRST 2026-09-29).
//   POST   /api/v1/vpn/sessions/{sessionId}/heartbeat
//   DELETE /api/v1/vpn/sessions/{sessionId}
// ONE rolled-back transaction. Sibling suite: test_vpn_session_rc.php, which covers POST /vpn/sessions.
//
// ★ THIS SUITE ASSERTS THE OPPOSITE STATUS-CODE RULE FROM ITS SIBLING, AND THAT IS THE POINT.
// The sibling proves a refusal is never 200, because the app reads a 200 without a status as ACTIVE. On
// HEARTBEAT the app inverts it: VpnSessionApi.heartbeat catches VpnException and returns null
// (vpn_session_api.dart:80-86), and _startHeartbeat treats null as "no news this cycle" and KEEPS THE TUNNEL
// UP (vpn_connection_manager.dart:219-223). So on this route:
//     4xx           -> swallowed by the app -> a revoked session keeps tunnelling  = FAIL-OPEN
//     200 + REVOKED -> the app calls disconnect() immediately                      = FAIL-CLOSED
// Every heartbeat refusal below therefore asserts 200 AND status REVOKED, and asserts explicitly that it is
// NOT a 4xx. If someone "corrects" the controller to 403 for consistency, H9-H10, T2, D4 and R1 fail - which is
// the only mechanism that stops that change shipping silently.
//
// DELETE keeps the ordinary rule: 404 + SESSION_NOT_FOUND for a session that is not yours. The app swallows it
// (endSession catches VpnException on purpose), so the identifier is never rendered; the value of answering 404
// rather than 204 is that D5 can prove one account cannot end another account's session.
//
// WHY THE ROW IS NOT DELETED ON RELEASE. Release sets status ENDED. A deleted row cannot answer "did this
// device ever hold a session", which is the only thing this table is for.
//
// ★ WHICH RESULTS ARE SQLITE-ONLY. The test panel DB is SQLite (RC_CONTRACTS.md §1.2-c) and whereJsonContains
// compiles per engine - json_each on SQLite, JSON_CONTAINS on MySQL. Same rule as the sibling: a result is
// marked when it is REACHED THROUGH that match. Here every session must be ISSUED first, so anything operating
// on a real session rides on that grant and is marked. NOT MARKED: refusals that need no session at all
// (H9 unknown id, D7, A1, A2) and T4, whose subject row is inserted directly rather than issued. The TTL
// arithmetic itself is COALESCE - plain SQL on both engines, with no JSON function anywhere near it.
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_vpn_session_lifecycle_rc.php
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

$createUri = $route('Vpn\VpnSessionController@create');
$beatUri = $route('Vpn\VpnSessionController@heartbeat');
$delUri = $route('Vpn\VpnSessionController@destroy');
t_check('setup: heartbeat route registered', $beatUri !== null, (string) $beatUri);
t_check('setup: release route registered', $delUri !== null, (string) $delUri);
// The paths are the app's, not ours (vpn_session_api.dart:80, :91). Asserted separately from "registered" so a
// route that exists under the wrong path is a distinct failure from a route that does not exist.
t_check('setup: heartbeat path is the one the app calls', $beatUri === '/api/v1/vpn/sessions/{sessionId}/heartbeat', (string) $beatUri);
t_check('setup: release path is the one the app calls', $delUri === '/api/v1/vpn/sessions/{sessionId}', (string) $delUri);
// Literal fallbacks so the requests below still execute on pre-change code instead of aborting the run.
$CREATE = $createUri ?? '/api/v1/vpn/sessions';
$BEAT = fn ($sid) => '/api/v1/vpn/sessions/' . $sid . '/heartbeat';
$DEL = fn ($sid) => '/api/v1/vpn/sessions/' . $sid;

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
// "everything passed". The sibling learned this from a run that aborted and still exited 0.
$completed = false;

DB::beginTransaction();
try {
    // DELIBERATE DIFFERENCES FROM THE SIBLING'S SKELETON, each with its reason - anything not listed is a bug:
    //  - $req takes a METHOD and a FULL PATH. The sibling posts to one fixed URI; this suite drives three.
    //  - It also returns the RAW body, because D1 has to assert a 204 carries no body at all.
    //  - REMOTE_ADDR uses 10.254.x (sibling: 10.253.x, profile suite: 10.252.x) so the three suites' requests
    //    stay distinguishable in any log that records them.
    //  - $revoked replaces $refused for heartbeat and asserts 200 + REVOKED instead of 4xx (see the header).
    //    $refused is kept as well, for DELETE, where the ordinary rule still holds.
    //  - Fixtures reuse the sibling's $mkUser / $mkServer column lists KEY FOR KEY. Two fixture bugs in two
    //    runs (`rate` missing, then ['auth_data'] missing) were both deviations from that list: a fixture's
    //    field list is dictated by the TABLE's NOT NULL columns, not by what the code under test reads.
    //    $mkServer drops the sibling's $show / $parentId arguments because eligibility is the sibling's subject,
    //    not this one's - here one eligible node is all the fixture needs.
    $req = function (string $method, string $path, ?array $body, $auth = null) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '10.254.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->headers->get('Cache-Control'), $res->getContent()];
    };
    // A heartbeat refusal is CORRECT only if it is 200 AND says REVOKED. Both halves are asserted, because
    // either alone passes on code that is wrong in the dangerous direction: a 403 satisfies "not ACTIVE", and a
    // 200 with no status satisfies "http 200" while the app reads it as still connected.
    $revoked = function ($label, $sid, $auth) use ($req, $BEAT) {
        [$st, $j] = $req('POST', $BEAT($sid), [], $auth);
        t_check($label, $st === 200 && (($j['data']['status'] ?? null) === 'REVOKED'),
            "http=$st status=" . json_encode($j['data']['status'] ?? null));
        t_check("$label -- and it is NOT a 4xx (the app swallows a 4xx heartbeat and keeps the tunnel)", $st < 400, "http=$st");
        return $j;
    };
    $refused = function ($label, $method, $path, $auth, $expectStatus, $expectError) use ($req) {
        [$st, $j] = $req($method, $path, null, $auth);
        t_check($label, $st === $expectStatus && ($j['error'] ?? null) === $expectError,
            "http=$st error=" . json_encode($j['error'] ?? null));
        return $j;
    };

    $mkUser = function (int $groupId) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-life-' . Str::lower(Str::random(10)) . '@example.invalid',
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
    $mkServer = function (string $type, array $groupIds, array $settings = []) {
        $s = new Server();
        $s->forceFill(['type' => $type])->forceFill([
            'code' => 'rbl-' . Str::lower(Str::random(12)),
            'name' => 'rb-life-' . Str::random(6), 'rate' => 1,
            'host' => '198.51.100.' . random_int(1, 254), 'port' => '443', 'server_port' => 443,
            'group_ids' => $groupIds, 'route_ids' => [], 'tags' => [],
            'show' => true, 'sort' => 999, 'parent_id' => null,
            'protocol_settings' => $settings,
        ])->save();
        return $s;
    };

    $group = 800 + random_int(1, 90);
    $user = $mkUser($group);
    // generateAuthData() RETURNS AN ARRAY and the bearer string is its 'auth_data' key; passing the array
    // itself reaches Symfony ServerBag::getHeaders and throws "TypeError: array given".
    $auth = (new AuthService(User::find($user->id)))->generateAuthData()['auth_data'];
    $other = $mkUser($group + 1);
    $otherAuth = (new AuthService(User::find($other->id)))->generateAuthData()['auth_data'];

    $hy2 = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2, 'tls' => ['server_name' => 'life.example.invalid']]);

    // PRECONDITION, NOT AN ASSERTION (HY2-CRED-1). A hysteria2 session is only issued to a caller who already
    // holds a live short-lived credential, and the credential is issued by the PROFILE read - so a suite that
    // creates a session without fetching a profile first is asking for something no real client asks for.
    // THE FIXTURE WALKS THE CLIENT'S OWN ORDER rather than reaching into the service: home_page:396 fetches the
    // profile, then :408 connect -> vpn_connection_manager:117 requests the session. Nothing here is asserted -
    // whether issuance works is test_vpn_hy2_credential_rc.php's job - and L1 below is the sentinel that reports
    // this fixture's health, so a missing credential cannot be mistaken for a broken heartbeat.
    $profileUri = $route('User\Hysteria2ProfileController@profile') ?? '/api/v1/user/server/hysteria2/profile';
    $req('GET', $profileUri . '?server_id=' . $hy2->id, null, $auth);

    // Every lifecycle assertion needs an ISSUED session, so issuance is the fixture here. L1 reports the
    // helper's own health, so a broken fixture is not mistaken for a broken heartbeat.
    $issue = function (string $deviceId) use ($req, $CREATE, $auth, $hy2) {
        [$st, $j] = $req('POST', $CREATE, ['deviceId' => $deviceId, 'protocol' => 'hysteria2',
            'routingMode' => 'FULL_TUNNEL', 'serverId' => (string) $hy2->id], $auth);
        return [$st, $j['data']['sessionId'] ?? null];
    };

    [$stIssue, $sid1] = $issue('life-device-0001');
    t_check('L1 a session can be issued to drive the lifecycle from (on SQLite)',
        $stIssue === 200 && is_string($sid1) && $sid1 !== '', "http=$stIssue");

    // --- HEARTBEAT, the granted path ---
    [$stH, $jH, $ccH] = $req('POST', $BEAT((string) $sid1), [], $auth);
    $dH = $jH['data'] ?? [];
    t_check('H1 heartbeat on an ACTIVE session is 200 and stays ACTIVE (on SQLite)',
        $stH === 200 && ($dH['status'] ?? null) === 'ACTIVE', "http=$stH status=" . json_encode($dH['status'] ?? null));
    t_check('H2 sessionId comes back unchanged (the app non-null casts it) (on SQLite)',
        ($dH['sessionId'] ?? null) === $sid1, json_encode($dH['sessionId'] ?? null));
    t_check('H3 lastHeartbeat is now a parseable date, where create left it null (on SQLite)',
        isset($dH['lastHeartbeat']) && strtotime((string) $dH['lastHeartbeat']) !== false,
        json_encode($dH['lastHeartbeat'] ?? null));
    t_check('H4 serverId is still a STRING, not an int (same cast as create) (on SQLite)',
        is_string($dH['serverId'] ?? null) && ($dH['serverId'] ?? null) === (string) $hy2->id,
        var_export($dH['serverId'] ?? null, true));
    t_check('H5 connectionConfig key is ABSENT (it would overwrite the verified profile credential) (on SQLite)',
        !array_key_exists('connectionConfig', $dH), json_encode(array_keys($dH)));
    t_check('H6 heartbeat response is not cacheable (Cache-Control: no-store) (on SQLite)',
        str_contains((string) $ccH, 'no-store'), (string) $ccH);
    t_check('H7 no credential value appears anywhere in the heartbeat body (on SQLite)',
        !str_contains(json_encode($jH), (string) $user->uuid) && !str_contains(json_encode($jH), (string) $user->token));
    if ($hasTable) {
        $row = DB::table('v2_vpn_session')->where('session_id', (string) $sid1)->first();
        t_check('H8 the row now carries last_heartbeat_at, and it is not before connected_at (on SQLite)',
            $row !== null && $row->last_heartbeat_at !== null && (int) $row->last_heartbeat_at >= (int) $row->connected_at,
            $row === null ? 'no row' : json_encode(['hb' => $row->last_heartbeat_at, 'conn' => $row->connected_at]));
    } else {
        t_check('H8 the row now carries last_heartbeat_at, and it is not before connected_at (on SQLite)', false, 'table missing');
    }

    // --- HEARTBEAT refusals. All 200 + REVOKED. Read the header before changing any of these. ---
    $revoked('H9 an unknown session id answers REVOKED, not 4xx (a 4xx would keep the tunnel up)',
        'no-such-session-' . Str::random(8), $auth);
    $jForeign = $revoked('H10 another account cannot beat this session - it answers REVOKED (on SQLite)',
        (string) $sid1, $otherAuth);
    t_check('H11 that answer carries only sessionId and status, so an id cannot be probed for existence',
        array_keys($jForeign['data'] ?? []) === ['sessionId', 'status'], json_encode(array_keys($jForeign['data'] ?? [])));
    if ($hasTable) {
        t_check('H12 and the foreign beat did not touch my row (it is still ACTIVE) (on SQLite)',
            DB::table('v2_vpn_session')->where('session_id', (string) $sid1)->value('status') === 'ACTIVE',
            json_encode(DB::table('v2_vpn_session')->where('session_id', (string) $sid1)->value('status')));
    } else {
        t_check('H12 and the foreign beat did not touch my row (it is still ACTIVE) (on SQLite)', false, 'table missing');
    }

    // --- TTL: the sweep is what makes ACTIVE mean anything. COALESCE arithmetic, identical on both engines. ---
    [, $sidStale] = $issue('life-device-stale');
    [, $sidFresh] = $issue('life-device-fresh');
    if ($hasTable && $sidStale !== null && $sidFresh !== null) {
        // Older than HEARTBEAT_TTL_SECONDS (300) by a clear margin, set on the row rather than by waiting.
        DB::table('v2_vpn_session')->where('session_id', (string) $sidStale)->update(['last_heartbeat_at' => time() - 3600]);
        // A beat on a DIFFERENT session must still sweep the stale one: the sweep is per user, not per row.
        [$stFresh, $jFresh] = $req('POST', $BEAT((string) $sidFresh), [], $auth);
        $staleStatus = DB::table('v2_vpn_session')->where('session_id', (string) $sidStale)->value('status');
        t_check('T1 beating one session also expires this account\'s other stale session (the sweep is per user) (on SQLite)',
            $stFresh === 200 && ($jFresh['data']['status'] ?? null) === 'ACTIVE' && $staleStatus === 'EXPIRED',
            "fresh=$stFresh stale=" . json_encode($staleStatus));
        $revoked('T2 the expired session itself now answers REVOKED (on SQLite)', (string) $sidStale, $auth);
        t_check('T3 a session inside the TTL is NOT swept (the sweep expires the quiet, not the connected) (on SQLite)',
            DB::table('v2_vpn_session')->where('session_id', (string) $sidFresh)->value('status') === 'ACTIVE',
            json_encode(DB::table('v2_vpn_session')->where('session_id', (string) $sidFresh)->value('status')));
        // Scoping: a stale row belonging to someone else is not mine to expire. Inserted directly because
        // $other has no eligible node; the field list is every NOT NULL column of v2_vpn_session.
        DB::table('v2_vpn_session')->insert([
            'session_id' => 'life-foreign-' . Str::lower(Str::random(12)),
            'user_id' => (int) $other->id, 'device_id' => 'life-device-foreign', 'server_id' => (int) $hy2->id,
            'protocol' => 'hysteria2', 'routing_mode' => 'FULL_TUNNEL', 'status' => 'ACTIVE',
            'connected_at' => time() - 7200, 'last_heartbeat_at' => time() - 7200,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        [$stSweep] = $req('POST', $BEAT((string) $sidFresh), [], $auth);
        $otherStatus = DB::table('v2_vpn_session')->where('user_id', (int) $other->id)->value('status');
        t_check('T4 my sweep does not expire another account\'s stale row (it is scoped to user_id)',
            $stSweep === 200 && $otherStatus === 'ACTIVE', "http=$stSweep other=" . json_encode($otherStatus));
    } else {
        t_check('T1 beating one session also expires this account\'s other stale session (the sweep is per user) (on SQLite)', false, 'table missing or session not issued');
        t_check('T2 the expired session itself now answers REVOKED (on SQLite)', false, 'table missing or session not issued');
        t_check('T2 the expired session itself now answers REVOKED (on SQLite) -- and it is NOT a 4xx (the app swallows a 4xx heartbeat and keeps the tunnel)', false, 'table missing or session not issued');
        t_check('T3 a session inside the TTL is NOT swept (the sweep expires the quiet, not the connected) (on SQLite)', false, 'table missing or session not issued');
        t_check('T4 my sweep does not expire another account\'s stale row (it is scoped to user_id)', false, 'table missing or session not issued');
    }

    // --- RELEASE ---
    [, $sidDel] = $issue('life-device-release');
    [$stD, , , $bodyD] = $req('DELETE', $DEL((string) $sidDel), null, $auth);
    t_check('D1 releasing an ACTIVE session is 204 with an empty body (on SQLite)',
        $stD === 204 && trim((string) $bodyD) === '', "http=$stD body=" . json_encode(substr((string) $bodyD, 0, 40)));
    if ($hasTable) {
        t_check('D2 the row is ENDED, not deleted - the record of issuance survives (on SQLite)',
            DB::table('v2_vpn_session')->where('session_id', (string) $sidDel)->value('status') === 'ENDED',
            json_encode(DB::table('v2_vpn_session')->where('session_id', (string) $sidDel)->value('status')));
    } else {
        t_check('D2 the row is ENDED, not deleted - the record of issuance survives (on SQLite)', false, 'table missing');
    }
    [$stD2] = $req('DELETE', $DEL((string) $sidDel), null, $auth);
    t_check('D3 releasing the same session again is still 204 (idempotent, because it is true) (on SQLite)', $stD2 === 204, "http=$stD2");
    $revoked('D4 a released session answers REVOKED on heartbeat, so the app disconnects (on SQLite)', (string) $sidDel, $auth);

    [, $sidGuard] = $issue('life-device-guard');
    $refused('D5 another account cannot release my session (404, no enumeration) (on SQLite)',
        'DELETE', $DEL((string) $sidGuard), $otherAuth, 404, 'SESSION_NOT_FOUND');
    if ($hasTable) {
        t_check('D6 and my session is still ACTIVE after that attempt (on SQLite)',
            DB::table('v2_vpn_session')->where('session_id', (string) $sidGuard)->value('status') === 'ACTIVE',
            json_encode(DB::table('v2_vpn_session')->where('session_id', (string) $sidGuard)->value('status')));
    } else {
        t_check('D6 and my session is still ACTIVE after that attempt (on SQLite)', false, 'table missing');
    }
    $refused('D7 releasing an unknown session id is 404 with the same identifier',
        'DELETE', $DEL('no-such-session-' . Str::random(8)), $auth, 404, 'SESSION_NOT_FOUND');

    // --- auth: no token, no lifecycle. Both routes sit behind the `user` middleware, which answers 403. ---
    [$stAnonBeat] = $req('POST', $BEAT((string) $sidGuard), [], null);
    t_check('A1 unauthenticated heartbeat is refused with 403', $stAnonBeat === 403, "http=$stAnonBeat");
    [$stAnonDel] = $req('DELETE', $DEL((string) $sidGuard), null, null);
    t_check('A2 unauthenticated release is refused with 403', $stAnonDel === 403, "http=$stAnonDel");

    // --- entitlement lost while connected. LAST, because it disables this user for everything after it. ---
    DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => time() - 3600]);
    $revoked('R1 an expired subscription revokes the live session on the next beat (on SQLite)', (string) $sidGuard, $auth);
    if ($hasTable) {
        t_check('R2 and the row records REVOKED, not EXPIRED - the reason is kept server-side (on SQLite)',
            DB::table('v2_vpn_session')->where('session_id', (string) $sidGuard)->value('status') === 'REVOKED',
            json_encode(DB::table('v2_vpn_session')->where('session_id', (string) $sidGuard)->value('status')));
    } else {
        t_check('R2 and the row records REVOKED, not EXPIRED - the reason is kept server-side (on SQLite)', false, 'table missing');
    }

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
t_check('Z3 row counts unchanged, INCLUDING every session this suite issued',
    DB::table('v2_server')->count() === $serverCountBefore
    && DB::table('v2_user')->count() === $userCountBefore
    && (!$hasTable || DB::table('v2_vpn_session')->count() === $sessionCountBefore),
    $hasTable ? ('sessions=' . DB::table('v2_vpn_session')->count() . ' before=' . $sessionCountBefore) : 'table missing');
t_check('Z4 the suite reached the end of its assertion block (a partial run is not a pass)', $completed);

echo ($fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n");
exit($fails === 0 ? 0 : 1);
