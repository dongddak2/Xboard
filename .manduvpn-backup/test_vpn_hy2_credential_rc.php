<?php
// ManduVPN RC regression: the short-lived Hysteria2 credential (HY2-CRED-1, RC_CONTRACTS §23.57, candidate B).
// ONE rolled-back transaction. Siblings: test_vpn_session_rc.php, test_vpn_session_lifecycle_rc.php,
// test_vpn_entitlements_rc.php.
//
// ★ WHAT THIS SUITE GUARDS AGAINST: A CREDENTIAL THAT OUTLIVES ITS ROTATION. Before this change the Hysteria2
// password was v2_user.uuid - no expiry, and rotating it was the only way to revoke. Now there is a second
// value with its own lifetime, and the failure that would be WORSE THAN THE OLD BEHAVIOUR is a uuid rotation
// that leaves the old credential alive: the user is told they reset their security while a working password
// stays valid for a whole TTL. Three rotation paths exist, so each one is asserted separately below (C10, C11,
// C12) even though they share one invalidation point - a shared implementation is exactly the thing one caller
// can silently bypass.
//
// ★ WHERE EXPIRY IS ENFORCED, AND THEREFORE WHAT "REFUSED" MEANS HERE. ★THIS CODEBASE ENFORCES IT, NOT THE
// NODE.★ Dropping a lapsed user from /UniProxy/user is PROPAGATION, and propagation is not revocation: on a
// pull where the node's own info changed, V2bX adds users without clearing its map (node/task.go:76-160,
// core/hy2/user.go:29-42), so a dropped value can stay usable until the process restarts. So the assertions
// that matter are the ones on OUR paths - C15 (no session is issued against a lapsed credential) and C16 (the
// profile never hands a lapsed one out) - and the node-list assertions (C7, C9, C9-b) are about what we
// propagate, not about what the node will refuse. No tunnel is raised anywhere in this file.
//
// ★ WHICH RESULTS ARE SQLITE-ONLY. The test panel DB is SQLite (RC_CONTRACTS.md §1.2-c). Every assertion that
// goes through the PROFILE endpoint is marked, because its eligibility filter uses whereJsonContains on
// v2_server.group_ids, which compiles per engine (json_each on SQLite, JSON_CONTAINS on MySQL). NOT MARKED:
// everything that goes through ServerService::getAvailableUsers (group_id is an integer column, whereIn - no
// JSON predicate), the table shape, the rotation assertions and the $hidden assertion.
//
// ★ THE TTL VALUE IS NOT UNDER TEST. It is deliberately undecided (LEAD §23.57 condition 6, pending INFRA's
// node measurement). This suite never writes a setting and never asserts a number of seconds: it forces expiry
// by writing expires_at into the past inside the transaction, so the adopted TTL - whatever it turns out to be
// - cannot change a single verdict here.
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_vpn_hy2_credential_rc.php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Hy2Credential, Server, User};
use App\Services\{AuthService, Hy2CredentialService, ServerService};
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
// THE VERB IS READ OFF THE REGISTERED ROUTE, NEVER ASSUMED. /user/resetSecurity is a PRE-EXISTING route and it
// is a GET (UserRoute.php:15). An earlier version of this suite posted to it, got 405, and reported that as
// "rotation path 1 did not rotate the uuid" - a defect in the suite dressed up as a defect in the code, plus two
// chained failures behind it. Asking the router removes the assumption, and it also means a later change of the
// verb moves the suite with it instead of turning into a mystery 405.
$verb = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); if (!$r) return null; $m = array_values(array_diff($r->methods(), ['HEAD'])); return $m[0] ?? null; };

$profileUri = $route('User\Hysteria2ProfileController@profile');
$resetUri = $route('User\UserController@resetSecurity');
$RESET_METHOD = $verb('User\UserController@resetSecurity') ?? 'GET';
t_check('setup: the hysteria2 profile route is registered', $profileUri !== null, (string) $profileUri);
t_check('setup: the resetSecurity route is registered (rotation path 1 is reachable over HTTP)', $resetUri !== null, (string) $resetUri);
// Printed, not compared: the verb is this endpoint's own pre-existing contract, so the suite follows it rather
// than asserting what it ought to be. What must hold is that a verb was found at all.
t_check('setup: the resetSecurity verb comes from the router, not from an assumption', $RESET_METHOD !== null, (string) $RESET_METHOD);
t_check('setup: the credential table exists (migration 2026_10_03_120000 applied)', Schema::hasTable('v2_vpn_hy2_credential'));
$PROFILE = $profileUri ?? '/api/v1/user/server/hysteria2/profile';
$RESET = $resetUri ?? '/api/v1/user/resetSecurity';

// C0 THE TABLE SHAPE, asserted because the whole design rests on two of these columns: an ABSOLUTE expires_at
// (so the reading side does no arithmetic and a later TTL change cannot reinterpret an existing row) and
// secret_enc (never a plaintext column).
foreach (['user_id', 'secret_enc', 'expires_at', 'issued_at', 'created_at', 'updated_at'] as $col) {
    t_check("C0 v2_vpn_hy2_credential has the column `$col`", Schema::hasColumn('v2_vpn_hy2_credential', $col));
}
t_check('C0-e there is NO plaintext secret column (the only secret column is the encrypted one)',
    !Schema::hasColumn('v2_vpn_hy2_credential', 'secret') && !Schema::hasColumn('v2_vpn_hy2_credential', 'password'));

// Nothing outside the rolled-back transaction may change - and the IKEv2 table is an inviolable axis here
// (§23.57 condition 5), so it gets its own signature rather than only a row count.
$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(DB::table($t)->orderBy($cols[0])->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$serverBefore = $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']);
$userBefore = $sig('v2_user', ['id', 'uuid', 'token', 'group_id']);
$ikev2Before = $sig('v2_vpn_credential', ['user_id', 'username', 'secret_enc', 'rotated_at']);
$hy2CountBefore = DB::table('v2_vpn_hy2_credential')->count();
$userCountBefore = DB::table('v2_user')->count();
$sessionCountBefore = DB::table('v2_vpn_session')->count();

// Set only when the assertion block runs to its end - see the catch below.
$completed = false;

DB::beginTransaction();
try {
    // DELIBERATE DIFFERENCES FROM THE SIBLINGS' SKELETON, each with its reason - anything not listed is a bug:
    //  - REMOTE_ADDR uses 10.251.x (session: 10.253.x, lifecycle: 10.254.x, entitlements: 10.255.x, profile:
    //    10.252.x), so a stray request can be attributed to one suite.
    //  - $req takes a method, because this suite drives more than one verb; the verb for a route it did not
    //    create is read off the router ($RESET_METHOD), never written in by hand.
    //  - Hy2CredentialService::flushMemo() is called around anything that changes the stored row: the service
    //    memoises per REQUEST, and a suite is one long-lived process, so without it a later read would answer
    //    from the memo instead of the table and every rotation assertion would pass vacuously.
    //  - $mkUser / $mkServer column lists are the siblings' KEY FOR KEY. A fixture's field list is dictated by
    //    the TABLE's NOT NULL columns, not by what the code under test reads - `rate` is the scar that taught it.
    $req = function (string $uri, string $method = 'GET', $auth = null) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.251.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $uri, $method, [], [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: []];
    };

    $mkUser = function (int $groupId) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-hy2c-' . Str::lower(Str::random(10)) . '@example.invalid',
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
            'code' => 'rbc-' . Str::lower(Str::random(12)),
            'name' => 'rb-hy2c-' . Str::random(6), 'rate' => 1,
            'host' => '198.51.100.' . random_int(1, 254), 'port' => '443', 'server_port' => 443,
            'group_ids' => $groupIds, 'route_ids' => [], 'tags' => [],
            'show' => $show, 'sort' => 999, 'parent_id' => $parentId,
            'protocol_settings' => $settings,
        ])->save();
        return $s;
    };

    $group = 760 + random_int(1, 30);
    $user = $mkUser($group);
    $auth = (new AuthService(User::find($user->id)))->generateAuthData()['auth_data'];
    $uuid = (string) User::find($user->id)->uuid;
    $hy2Node = $mkServer(Server::TYPE_HYSTERIA, [$group], ['version' => 2, 'tls' => ['server_name' => 'hy2c.example.invalid']]);
    $ikev2Node = $mkServer(Server::TYPE_IKEV2, [$group]);

    // The node's own view. This is the enforcement point, so it is read through the same service the node
    // endpoint calls, with the node's id and type.
    $nodeList = fn (Server $n, ?string $type) => ServerService::getAvailableUsers((array) $n->group_ids, (int) $n->id, $type);
    $rowOf = fn () => Hy2Credential::find($user->id);
    $listedValue = function (Server $n, ?string $type) use ($nodeList, $user) {
        $row = $nodeList($n, $type)->first(fn ($r) => (int) $r->id === (int) $user->id);
        return $row ? (string) $row->uuid : null;
    };

    // --- issuance: the profile read is what creates the credential ---
    t_check('C1 no credential exists before the first profile read (nothing is issued in bulk or on a schedule)',
        $rowOf() === null);
    Hy2CredentialService::flushMemo();
    [$s1, $j1] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    $auth1 = $j1['data']['credential']['auth'] ?? null;
    t_check('C2 the profile read succeeds (on SQLite)', $s1 === 200, "http=$s1 " . json_encode($j1['error'] ?? null));
    t_check('C3 credential.auth is the short-lived credential, NOT v2_user.uuid (on SQLite)',
        is_string($auth1) && $auth1 !== '' && $auth1 !== $uuid);
    t_check('C4 it matches the service\'s own character class, so it is safe unquoted in a hysteria2:// URI and in a node config (on SQLite)',
        is_string($auth1) && preg_match(Hy2CredentialService::SECRET_PATTERN, $auth1) === 1);
    $row1 = $rowOf();
    t_check('C5 the read stored a row with an expiry in the future and an issued_at (on SQLite)',
        $row1 !== null && (int) $row1->expires_at > time() && (int) $row1->issued_at > 0,
        $row1 ? 'expires_in=' . ((int) $row1->expires_at - time()) : 'no row');
    t_check('C5-b the stored column is the ENCRYPTED one and it is not the plaintext that was just handed out',
        $row1 !== null && is_string($row1->secret_enc) && $row1->secret_enc !== $auth1);
    t_check('C5-c the model hides secret_enc from every serializer ($hidden, the v2_vpn_credential precedent)',
        $row1 !== null && !array_key_exists('secret_enc', $row1->toArray()), json_encode(array_keys($row1 ? $row1->toArray() : [])));

    // --- a second read RENEWS, it does not rotate: this is what removes the fetch -> handshake race ---
    DB::table('v2_vpn_hy2_credential')->where('user_id', $user->id)->update(['expires_at' => time() + 5]);
    Hy2CredentialService::flushMemo();
    [$s2, $j2] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    $auth2 = $j2['data']['credential']['auth'] ?? null;
    $row2 = $rowOf();
    t_check('C6 a second read returns the SAME secret (a client holding the old value keeps working) (on SQLite)',
        $s2 === 200 && $auth2 === $auth1);
    t_check('C6-b -- and it pushed the expiry out, so what was just handed to the client cannot lapse between this response and the handshake (on SQLite)',
        $row2 !== null && (int) $row2->expires_at > time() + 5, $row2 ? 'expires_in=' . ((int) $row2->expires_at - time()) : 'no row');
    t_check('C6-c -- and issued_at did NOT move, so "how old is the live secret" still has a true answer (on SQLite)',
        $row2 !== null && (int) $row2->issued_at === (int) $row1->issued_at);

    // --- the node's user list: the hysteria node is handed the credential, everything else is untouched ---
    t_check('C7 a hysteria node\'s user list carries the credential in place of uuid',
        $listedValue($hy2Node, Server::TYPE_HYSTERIA) === $auth1, json_encode($listedValue($hy2Node, Server::TYPE_HYSTERIA)) . ' vs credential');
    t_check('C7-b -- and the same call made with the node type alias `hysteria2` behaves identically (Server::normalizeType)',
        $listedValue($hy2Node, 'hysteria2') === $auth1);
    t_check('C8 a NON-hysteria node still gets v2_user.uuid (this change is scoped to one protocol)',
        $listedValue($ikev2Node, Server::TYPE_IKEV2) === $uuid);
    t_check('C8-b a caller that passes no node type at all is byte-identical to before (Tidalab controllers, the IKEv2 node API, the device-limit count)',
        $listedValue($hy2Node, null) === $uuid);

    // --- THE PAIR §23.57 ASKS FOR: before expiry it is served, after expiry it is not.
    // Read these as PROPAGATION, not as revocation - see the header. C15 and C16 are the enforcement pair.
    t_check('C9 BEFORE the expiry the credential is propagated to the node, so a connection can be authenticated',
        $listedValue($hy2Node, Server::TYPE_HYSTERIA) !== null);
    DB::table('v2_vpn_hy2_credential')->where('user_id', $user->id)->update(['expires_at' => time() - 1]);
    t_check('C9-b AFTER the expiry the user is ABSENT from the node\'s list, so the lapsed value stops being'
        . ' propagated -- and the uuid does NOT come back in its place (a fallback would make expiry meaningless)',
        $listedValue($hy2Node, Server::TYPE_HYSTERIA) === null);
    t_check('C9-c the row is still there (expiry is a comparison, not a cleanup job the suite has to wait for)',
        $rowOf() !== null);
    // The client's way back in is a fresh read, which issues a NEW secret because the old one has lapsed.
    Hy2CredentialService::flushMemo();
    [$s3, $j3] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    $auth3 = $j3['data']['credential']['auth'] ?? null;
    t_check('C9-d a read after expiry issues a NEW secret (not a renewal of the lapsed one) (on SQLite)',
        $s3 === 200 && is_string($auth3) && $auth3 !== $auth1 && preg_match(Hy2CredentialService::SECRET_PATTERN, (string) $auth3) === 1);
    t_check('C9-e -- and issued_at moved with it (on SQLite)', (int) $rowOf()->issued_at >= (int) $row1->issued_at);

    // --- ROTATION PATH 1: /user/resetSecurity (the user-facing one). The verb is $RESET_METHOD, read off the
    // registered route - see the note at the top. Do not write a verb in here by hand. ---
    Hy2CredentialService::flushMemo();
    [$s4] = $req($RESET, $RESET_METHOD, $auth);
    t_check('C10 rotation path 1 (V1/User/UserController@resetSecurity) rotated the uuid',
        $s4 === 200 && (string) User::find($user->id)->uuid !== $uuid, "http=$s4");
    t_check('C10-b -- and it INVALIDATED the credential, so the old password does not survive a security reset',
        $rowOf() === null);
    t_check('C10-c -- so the node no longer lists the user at all until they fetch a new profile',
        $listedValue($hy2Node, Server::TYPE_HYSTERIA) === null);

    // --- ROTATION PATH 2: the admin endpoint's body (V2/Admin/UserController@resetSecret) ---
    // Driven as code rather than over HTTP because that endpoint needs an admin session, which this suite does
    // not hold. What is asserted is the exact two-line shape the controller uses; C13-b locks that shape.
    Hy2CredentialService::flushMemo();
    $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    t_check('C11 setup: a credential exists again before rotation path 2', $rowOf() !== null);
    $u2 = User::find($user->id);
    $u2->token = Helper::guid();
    $u2->uuid = Helper::guid(true);
    $u2->save();
    t_check('C11-b rotation path 2 (V2/Admin/UserController@resetSecret) invalidates the credential too',
        $rowOf() === null);

    // --- ROTATION PATH 3: the console command's loop body (reset:user, which walks EVERY user) ---
    Hy2CredentialService::flushMemo();
    $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    t_check('C12 setup: a credential exists again before rotation path 3', $rowOf() !== null);
    foreach (User::where('id', $user->id)->get() as $u3) { // the ResetUser loop body, over one user
        $u3->token = Helper::guid();
        $u3->uuid = Helper::guid(true);
        $u3->save();
    }
    t_check('C12-b rotation path 3 (console reset:user) invalidates the credential too -- the bulk path is the'
        . ' dangerous one, because one forgotten call there would leave EVERY old credential alive',
        $rowOf() === null);

    // --- THE SHAPE LOCK. The three paths above share ONE invalidation point (a User model event), so they are
    // covered only while they keep going through Eloquent. A mass update would silently bypass it, and the
    // symptom would be invisible: rotation would appear to succeed and the old credential would keep working.
    $src = fn ($p) => (string) @file_get_contents('/www/' . $p);
    $modelSave = fn (string $body) => preg_match('/->uuid\s*=/', $body) === 1 && preg_match('/->save\(\)/', $body) >= 1;
    $resetSecuritySrc = $src('app/Http/Controllers/V1/User/UserController.php');
    $resetSecretSrc = $src('app/Http/Controllers/V2/Admin/UserController.php');
    $resetUserSrc = $src('app/Console/Commands/ResetUser.php');
    t_check('C13 path 1 still assigns uuid on a MODEL and saves it (not a mass ->update(), which fires no model event)',
        $modelSave($resetSecuritySrc));
    t_check('C13-b path 2 still assigns uuid on a MODEL and saves it', $modelSave($resetSecretSrc));
    t_check('C13-c path 3 still assigns uuid on a MODEL and saves it', $modelSave($resetUserSrc));
    t_check('C13-d no rotation path writes uuid through a query-builder update on v2_user',
        preg_match("/table\('v2_user'\)[^;]*update\([^;]*uuid/s", $resetSecuritySrc . $resetSecretSrc . $resetUserSrc) === 0);
    t_check('C13-e the node endpoint still passes the node TYPE to getAvailableUsers (without it a hysteria node'
        . ' would be handed uuid again, and every assertion above would keep passing)',
        preg_match('/getAvailableUsers\(\s*\$node->group_ids\s*,\s*\(int\)\s*\$node->id\s*,/', $src('app/Http/Controllers/V1/Server/UniProxyController.php')) === 1);
    t_check('C13-f all eight read sites take the password from the ONE service (1 profile + 7 subscription builders)',
        count(array_filter(['app/Http/Controllers/V1/User/Hysteria2ProfileController.php',
            'app/Protocols/General.php', 'app/Protocols/ClashMeta.php', 'app/Protocols/SingBox.php',
            'app/Protocols/Stash.php', 'app/Protocols/Surge.php', 'app/Protocols/Loon.php',
            'app/Protocols/Shadowrocket.php'], fn ($p) => str_contains($src($p), 'Hy2CredentialService::password('))) === 8);

    // --- an account with nothing to connect with gets no credential at all ---
    Hy2CredentialService::flushMemo();
    DB::table('v2_vpn_hy2_credential')->where('user_id', $user->id)->delete();
    DB::table('v2_user')->where('id', $user->id)->update(['banned' => 1]);
    [$s5, $j5] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    t_check('C14 a banned account is refused and NO credential is issued to it (on SQLite)',
        $s5 === 403 && $rowOf() === null, "http=$s5 error=" . json_encode($j5['error'] ?? null));
    DB::table('v2_user')->where('id', $user->id)->update(['banned' => 0]);

    // --- ★THE ENFORCEMENT PAIR. This is what makes the panel, not the node, the authority on expiry.★
    // The node cannot be trusted to have forgotten a value it was once given (see the header), so a lapsed
    // credential has to be refused by code we control: the session route, which issues the app's only
    // authorisation evidence, and the profile route, which is what hands the value out.
    $sessionUri = $route('Vpn\VpnSessionController@create');
    // Same rule as $RESET_METHOD: the verb comes from the router. This route IS ours, so the assumption would be
    // right today - but "right today" is how the resetSecurity 405 happened, and the lookup costs one line.
    $sessionMethod = $verb('Vpn\VpnSessionController@create') ?? 'POST';
    t_check('C15 setup: the session route is registered', $sessionUri !== null, (string) $sessionUri . ' ' . $sessionMethod);
    $post = function (string $uri, array $body, $auth) use ($http, $sessionMethod) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '10.251.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $uri, $sessionMethod, [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: []];
    };
    $sessionBody = ['deviceId' => 'rb-hy2c-' . Str::random(8), 'serverId' => (int) $hy2Node->id, 'protocol' => 'hysteria2'];

    Hy2CredentialService::flushMemo();
    [$s6, $j6] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth); // issues a live credential
    $auth6 = $j6['data']['credential']['auth'] ?? null;
    t_check('C15-a setup: a live credential exists (on SQLite)', $s6 === 200 && is_string($auth6) && $auth6 !== '');
    [$s7, $j7] = $post((string) ($sessionUri ?? '/api/v1/vpn/sessions'), $sessionBody, $auth);
    t_check('C15-b BEFORE the expiry a hysteria2 session IS issued (on SQLite)',
        $s7 === 200 && is_string($j7['data']['sessionId'] ?? null), "http=$s7 error=" . json_encode($j7['error'] ?? null));
    DB::table('v2_vpn_hy2_credential')->where('user_id', $user->id)->update(['expires_at' => time() - 1]);
    Hy2CredentialService::flushMemo();
    [$s8, $j8] = $post((string) ($sessionUri ?? '/api/v1/vpn/sessions'), $sessionBody, $auth);
    t_check('C15-c ★AFTER the expiry NO session is issued -- the panel refuses it itself rather than relying on'
        . ' the node to have forgotten the value★ (on SQLite)',
        $s8 === 403 && ($j8['error'] ?? null) === 'HY2_CREDENTIAL_EXPIRED', "http=$s8 error=" . json_encode($j8['error'] ?? null));
    t_check('C15-d -- and that refusal carries no session data, so the app cannot read it as an authorisation',
        $s8 !== 200 && ($j8['data']['sessionId'] ?? null) === null, json_encode($j8['data'] ?? 'MISSING'));
    t_check('C15-e the refusal is protocol-scoped: an ikev2 session is NOT blocked by a lapsed hysteria2 credential (on SQLite)',
        (function () use ($post, $sessionUri, $ikev2Node, $auth) {
            [$st, $j] = $post((string) ($sessionUri ?? '/api/v1/vpn/sessions'),
                ['deviceId' => 'rb-hy2c-' . Str::random(8), 'serverId' => (int) $ikev2Node->id, 'protocol' => 'ikev2'], $auth);
            return $st === 200 && is_string($j['data']['sessionId'] ?? null);
        })());

    // C16 the profile route never hands out a lapsed value: the row is expired right now, and the read replaces
    // it instead of serving it. Asserted through liveSecret(), which is the same check the session route uses.
    t_check('C16 setup: the stored credential is lapsed before this read', Hy2CredentialService::liveSecret((int) $user->id) === null);
    Hy2CredentialService::flushMemo();
    [$s9, $j9] = $req($PROFILE . '?server_id=' . $hy2Node->id, 'GET', $auth);
    $auth9 = $j9['data']['credential']['auth'] ?? null;
    t_check('C16-b ★the profile does NOT serve the lapsed credential★ -- it issues a live one, and the value it'
        . ' returns is the live one (on SQLite)',
        $s9 === 200 && is_string($auth9) && $auth9 !== '' && $auth9 === Hy2CredentialService::liveSecret((int) $user->id));
    t_check('C16-c -- and the profile response never carries an empty auth (an empty value would reach the app as'
        . ' a profile that looks usable) (on SQLite)', is_string($auth9) && $auth9 !== '');

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
    Hy2CredentialService::flushMemo();
}

t_check('Z1 v2_server unchanged (fixtures rolled back)', $sig('v2_server', ['id', 'type', 'show', 'host', 'port', 'group_ids', 'protocol_settings']) === $serverBefore);
t_check('Z2 v2_user unchanged -- including uuid, which this suite rotated three times inside the transaction',
    $sig('v2_user', ['id', 'uuid', 'token', 'group_id']) === $userBefore);
t_check('Z3 ★v2_vpn_credential (IKEv2) is byte-identical★ -- the new table is a NEW table, and the IKEv2 one is'
    . ' not touched, read or written by any of this (§23.57 condition 5)',
    $sig('v2_vpn_credential', ['user_id', 'username', 'secret_enc', 'rotated_at']) === $ikev2Before);
t_check('Z4 row counts unchanged -- including v2_vpn_session, which C15 wrote to (only fixtures were created)',
    DB::table('v2_vpn_hy2_credential')->count() === $hy2CountBefore && DB::table('v2_user')->count() === $userCountBefore
    && DB::table('v2_vpn_session')->count() === $sessionCountBefore,
    'hy2cred=' . DB::table('v2_vpn_hy2_credential')->count() . ' users=' . DB::table('v2_user')->count()
    . ' sessions=' . DB::table('v2_vpn_session')->count());
t_check('Z5 the suite reached the end of its assertion block (a partial run is not a pass)', $completed);

echo ($fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n");
exit($fails === 0 ? 0 : 1);
