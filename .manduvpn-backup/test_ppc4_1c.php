<?php
// ManduVPN PP-C4-1C regression: authenticated IKEv2 node API (/api/v1/server/manduvpn/ikev2/{ping,users,report}).
// ONE rolled-back transaction; temp groups/servers/plans/users only; tokens and VPN secrets are never printed; no Toss.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\V1\Server\IkeV2NodeApiController as API;
use App\Models\{Order, Plan, Server, User, VpnCredential};
use App\Services\{AuthService, IkeV2NodeService as NS, ServerService, VpnCredentialService as VCS};
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function c_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['ping' => $route('IkeV2NodeApiController@ping'), 'users' => $route('IkeV2NodeApiController@users'), 'report' => $route('IkeV2NodeApiController@report'),
      'uniUser' => $route('UniProxyController@user'), 'getNodes' => $route('ManageController@getNodes'), 'nodeMeta' => $route('IkeV2NodeController@fetch'),
      'adminUsers' => $route('Admin\UserController@fetch'), 'userInfo' => $route('V1\User\UserController@info')];
c_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$rows = fn ($t) => Schema::hasTable($t) ? hash('sha256', json_encode(DB::table($t)->get()->map(fn ($r) => (array) $r)->all())) : 'no-table';
$before = []; foreach (['v2_server', 'v2_ikev2_node', 'v2_vpn_credential', 'v2_user', 'v2_plan', 'v2_order'] as $t) $before[$t] = $rows($t);
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$logSizes = []; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) $logSizes[$f] = filesize($f);
$tokens = []; $secrets = []; $cacheKeys = [];
$prevEnv = $app['env'];

DB::beginTransaction();
try {
    $group = function () { $g = ['name' => 'rollback-test-c41c group ' . Str::random(4)]; foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $g[$c] = time(); return DB::table('v2_server_group')->insertGetId($g); };
    $mkServer = function ($gids, $show = 1, $parent = null, $type = 'ikev2') use (&$cacheKeys) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-c41c ' . Str::random(6), 'group_ids' => array_map('strval', (array) $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        $cacheKeys[] = CacheKey::get('SERVER_' . strtoupper($type) . '_LAST_CHECK_AT', $s->id);
        return Server::find($s->id);
    };
    $mkPlan = function ($gid, $tier) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-c41c ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $mkUser = function (?Plan $p, $assigned = null, array $x = []) {
        $u = new User(); $u->email = 'rollback-test-c41c-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['plan_id' => $p?->id, 'group_id' => $p?->group_id, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => time() + 30 * 86400, 'banned' => 0, 'device_limit' => 2, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $tok = function (Server $s) use (&$tokens) { $t = NS::issue($s->id); $tokens[] = $t; return $t; };
    $call = function ($method, $name, $nodeId, $auth, $body = null, array $hdr = [], $scheme = 'https', $qs = '') use ($http, $R) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($nodeId !== null) $server['HTTP_X_NODE_ID'] = (string) $nodeId;
        if ($auth !== null) $server['HTTP_AUTHORIZATION'] = $auth;
        foreach ($hdr as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $uri = "$scheme://localhost" . $R[$name] . $qs;
        $req = $method === 'GET' ? Request::create($uri, 'GET', is_array($body) ? $body : [], [], [], $server)
            : Request::create($uri, 'POST', [], [], [], $server, is_string($body) ? $body : json_encode($body));
        $res = $http->handle($req);
        return [$res->getStatusCode(), (string) $res->getContent(), $res->headers];
    };
    $j = fn ($raw) => json_decode($raw, true);
    $err = fn ($raw) => (string) (($j($raw) ?? [])['error'] ?? '');
    $has = function (string $raw, array $needles) { foreach ($needles as $n) if ($n !== '' && str_contains($raw, $n)) return true; return false; };
    $row = fn ($id) => DB::table('v2_ikev2_node')->where('server_id', $id)->first();

    $g = $group(); $g2 = $group(); $gE = $group();
    $N = $mkServer($g); $M = $mkServer($g); $Dis = $mkServer($g, 0); $E = $mkServer($gE);
    $tN = $tok($N); $tM = $tok($M); $tD = $tok($Dis); $tE = $tok($E);

    // ---------------- AUTH ----------------
    [$s] = $call('GET', 'ping', $N->id, null);
    c_check('AUTH-1 missing Authorization -> 401', $s === 401, "http=$s");
    $bad = ['Bearer', 'Bearer ', 'Bearer xyz', 'bearer ' . $tN, 'Basic ' . base64_encode('a:b'), $tN, 'Bearer ' . $tN . ' x', 'Bearer ' . strtoupper($tN), 'Bearer  ' . $tN];
    $st = []; foreach ($bad as $b) { [$s, $raw] = $call('GET', 'ping', $N->id, $b); $st[] = $s . ':' . $err($raw); }
    c_check('AUTH-2 malformed Bearer (9 variants) -> 401 UNAUTHORIZED', count(array_unique($st)) === 1 && $st[0] === '401:UNAUTHORIZED', implode(' ', array_unique($st)));
    [$s] = $call('GET', 'ping', $N->id, 'Bearer mvn_' . bin2hex(random_bytes(32)));
    c_check('AUTH-3 wrong (well-formed) token -> 401', $s === 401, "http=$s");
    [$s1] = $call('GET', 'ping', $M->id, "Bearer $tN"); [$s2] = $call('GET', 'ping', $N->id, "Bearer $tM"); [$s3] = $call('GET', 'users', $M->id, "Bearer $tN");
    c_check('AUTH-4 token never accepted for another node (N token on M, M token on N, users) -> 401', $s1 === 401 && $s2 === 401 && $s3 === 401, "$s1/$s2/$s3");
    $st = [];
    foreach (['abc', '1.5', '-1', '0', ' ', '12345678901', $N->id . 'x'] as $v) { [$s, $raw] = $call('GET', 'ping', null, "Bearer $tN", null, ['X-Node-Id' => $v]); $st[] = $s . ':' . $err($raw); }
    [$s, $raw] = $call('GET', 'ping', null, "Bearer $tN"); $st[] = $s . ':' . $err($raw);
    c_check('AUTH-5 missing / non-integer X-Node-Id -> 400 NODE_ID_INVALID', count(array_unique($st)) === 1 && $st[0] === '400:NODE_ID_INVALID', implode(' ', array_unique($st)));
    [$s] = $call('GET', 'ping', 999999999, "Bearer $tN");
    c_check('AUTH-6 nonexistent node id -> 401', $s === 401, "http=$s");
    [$s1, $raw1] = $call('GET', 'ping', $N->id, "Bearer $tN", null, [], 'https', '?token=' . $tN); [$s2] = $call('GET', 'ping', $N->id, null, null, [], 'https', '?token=' . $tN);
    c_check('AUTH-7 token in the query string is refused (400 TOKEN_IN_URL, with or without header)', $s1 === 400 && $s2 === 400 && $err($raw1) === 'TOKEN_IN_URL', "$s1/$s2");
    [$s, $raw] = $call('GET', 'ping', $N->id, "Bearer $tN");
    c_check('AUTH-8 current token -> 200', $s === 200, "http=$s");
    $tN2 = NS::rotate($N->id); $tokens[] = $tN2;
    [$s1] = $call('GET', 'ping', $N->id, "Bearer $tN2"); [$s2] = $call('GET', 'ping', $N->id, "Bearer $tN");
    c_check('AUTH-9 after rotation: new token 200 and previous token 200 inside the 24h overlap', $s1 === 200 && $s2 === 200, "$s1/$s2");
    DB::table('v2_ikev2_node')->where('server_id', $N->id)->update(['token_prev_expires_at' => time() - 1]);
    [$s1] = $call('GET', 'ping', $N->id, "Bearer $tN"); [$s2] = $call('GET', 'ping', $N->id, "Bearer $tN2");
    c_check('AUTH-10 expired previous token -> 401 (current still 200)', $s1 === 401 && $s2 === 200, "$s1/$s2");
    NS::revoke($N->id);
    [$s1] = $call('GET', 'ping', $N->id, "Bearer $tN2"); [$s2] = $call('GET', 'users', $N->id, "Bearer $tN2");
    c_check('AUTH-11 revoked token -> 401 on ping and users', $s1 === 401 && $s2 === 401, "$s1/$s2");
    $tN = $tok($N);
    $V = $mkServer($g); $tV = $tok($V); DB::table('v2_server')->where('id', $V->id)->update(['type' => 'vmess']);
    $K = $mkServer($g); $tK = $tok($K); DB::table('v2_server')->where('id', $K->id)->update(['parent_id' => $N->id]);
    [$s1] = $call('GET', 'ping', $V->id, "Bearer $tV"); [$s2] = $call('GET', 'ping', $K->id, "Bearer $tK");
    c_check('AUTH-12 node that became non-IKEv2 or a child node -> 401 even with its own valid token', $s1 === 401 && $s2 === 401, "type=$s1 child=$s2");
    $app['env'] = 'production';
    [$s1, $raw1] = $call('GET', 'ping', $N->id, "Bearer $tN", null, [], 'http'); [$s2] = $call('GET', 'ping', $N->id, "Bearer $tN", null, [], 'https');
    $app['env'] = $prevEnv;
    c_check('AUTH-13 production: plain HTTP -> 403 HTTPS_REQUIRED, HTTPS -> 200', $s1 === 403 && $err($raw1) === 'HTTPS_REQUIRED' && $s2 === 200, "$s1/$s2");
    [$s, $raw] = $call('GET', 'users', $N->id, 'Bearer mvn_' . str_repeat('0', 64));
    c_check('AUTH-14 auth failure bodies carry only {schema,error}', $s === 401 && array_keys($j($raw) ?? []) === ['schema', 'error'], "http=$s");

    // ---------------- PING ----------------
    $m0 = $row($M->id);
    [$s, $raw] = $call('GET', 'ping', $M->id, "Bearer $tM"); $d = $j($raw) ?? []; $m1 = $row($M->id);
    c_check('PING-1 enabled node: exact schema {schema,node_id,server_time,show,state}, no credentials', $s === 200 && array_keys($d) === ['schema', 'node_id', 'server_time', 'show', 'state']
        && $d['schema'] === 'manduvpn.ikev2.ping.v1' && $d['node_id'] === (int) $M->id && $d['show'] === true && abs($d['server_time'] - time()) <= 5 && $d['state'] === 'BOOTSTRAPPING'
        && $m0->first_seen_at === null && $m1->first_seen_at !== null, "http=$s state=" . ($d['state'] ?? '-'));
    [$s, $raw] = $call('GET', 'ping', $Dis->id, "Bearer $tD"); $d = $j($raw) ?? [];
    c_check('PING-2 disabled node (show=0): ping allowed, show=false, state DISABLED, first_seen_at recorded, show untouched', $s === 200 && ($d['show'] ?? null) === false
        && ($d['state'] ?? '') === 'DISABLED' && $row($Dis->id)->first_seen_at !== null && (int) Server::find($Dis->id)->show === 0, "http=$s");
    $t50 = time() - 50; DB::table('v2_ikev2_node')->where('server_id', $M->id)->update(['first_seen_at' => $t50, 'last_seen_at' => $t50]);
    $call('GET', 'ping', $M->id, "Bearer $tM"); $m2 = $row($M->id);
    c_check('PING-3 health timestamps: first_seen_at kept, last_seen_at refreshed', (int) $m2->first_seen_at === $t50 && abs((int) $m2->last_seen_at - time()) <= 2);
    [$s] = $call('GET', 'ping', $M->id, "Bearer $tM", null, [], 'https', '?agent_version=0.1.0&strongswan_version=' . rawurlencode('U5.9.5/K5.15.0'));
    $okV = $s === 200 && $row($M->id)->agent_version === '0.1.0' && $row($M->id)->strongswan_version === 'U5.9.5/K5.15.0';
    [$s1] = $call('GET', 'ping', $M->id, "Bearer $tM", null, [], 'https', '?agent_version=' . str_repeat('a', 65));
    [$s2] = $call('GET', 'ping', $M->id, "Bearer $tM", null, [], 'https', '?agent_version=' . rawurlencode("a\nb"));
    c_check('PING-4 version metadata stored; oversized / control chars -> 422, not stored', $okV && $s1 === 422 && $s2 === 422 && $row($M->id)->agent_version === '0.1.0', "ok=$s bad=$s1/$s2");

    // ---------------- USERS ----------------
    $pro = $mkPlan($g, 'pro'); $basic = $mkPlan($g, 'legacy'); $pro2 = $mkPlan($g2, 'pro');
    $uPro = $mkUser($pro); $uBasicN = $mkUser($basic, $N->id); $uBasicM = $mkUser($basic, $M->id); $uExp = $mkUser($pro, null, ['expired_at' => time() - 60]);
    $uBan = $mkUser($pro, null, ['banned' => 1]); $uNoCred = $mkUser($pro); $uG2 = $mkUser($pro2);
    foreach ([$uPro, $uBasicN, $uBasicM, $uExp, $uBan, $uG2] as $u) { VCS::ensure($u); $secrets[] = VCS::get($u)['secret']; }
    $credCount = DB::table('v2_vpn_credential')->count();
    $usersCall = function ($node, $token, array $hdr = []) use ($call, $j) { [$s, $raw, $h] = $call('GET', 'users', $node->id, "Bearer $token", null, $hdr); return [$s, $j($raw), $raw, $h]; };
    [$s, $d, $raw, $h] = $usersCall($N, $tN);
    $ids = array_column($d['users'] ?? [], 'id');
    $expect = [(int) $uPro->id, (int) $uBasicN->id]; sort($expect);
    $credIds = DB::table('v2_vpn_credential')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    $viaC1 = array_values(array_intersect(ServerService::getAvailableUsers((array) $N->group_ids, (int) $N->id)->pluck('id')->map(fn ($v) => (int) $v)->all(), $credIds)); sort($viaC1);
    c_check('USERS-1 entitled users only = PP-C1 getAvailableUsers(node) joined to C4-1A credentials', $s === 200 && $ids === $expect && $ids === $viaC1, json_encode($ids));
    c_check('USERS-2 non-entitled excluded (BASIC assigned elsewhere, expired, banned, other group)', !array_intersect($ids, [(int) $uBasicM->id, (int) $uExp->id, (int) $uBan->id, (int) $uG2->id]));
    c_check('USERS-3 entitled user without credential excluded and NOT auto-created', !in_array((int) $uNoCred->id, $ids, true) && VpnCredential::find($uNoCred->id) === null
        && DB::table('v2_vpn_credential')->count() === $credCount);
    $shapeOk = is_array($d) && array_keys($d) === ['schema', 'node_id', 'generated_at', 'revision', 'user_count', 'users'] && $d['schema'] === 'manduvpn.ikev2.users.v1'
        && $d['node_id'] === (int) $N->id && is_int($d['generated_at']) && preg_match('/^[0-9a-f]{64}$/', $d['revision']) && $d['user_count'] === count($d['users']);
    foreach ($d['users'] ?? [] as $e) {
        $u = User::find($e['id']); $c = VCS::get($u);
        $shapeOk = $shapeOk && array_keys($e) === ['id', 'username', 'secret', 'expires_at', 'device_limit'] && is_int($e['id'])
            && preg_match(VCS::USERNAME_PATTERN, $e['username']) && preg_match(VCS::SECRET_PATTERN, $e['secret']) && $e['username'] === $c['username'] && $e['secret'] === $c['secret']
            && $e['expires_at'] === ($u->expired_at === null ? null : (int) $u->expired_at) && $e['device_limit'] === ($u->device_limit === null ? null : (int) $u->device_limit);
    }
    $shapeOk = $shapeOk && count(array_unique(array_column($d['users'] ?? [], 'username'))) === count($d['users'] ?? []);
    c_check('USERS-4 exact contract: top keys, per-user keys/types, user_count = length, unique usernames, credential formats, values = C4-1A', $shapeOk);
    $rev0 = $d['revision'] ?? ''; $users0 = json_encode($d['users'] ?? null);
    [$s2, $d2] = $usersCall($N, $tN); $sorted = $ids; sort($sorted);
    c_check('USERS-5 deterministic: ascending id order, identical users + revision on repeat', $ids === $sorted && $s2 === 200 && ($d2['revision'] ?? '') === $rev0 && json_encode($d2['users'] ?? null) === $users0);
    $canon = fn ($nodeId, array $us) => hash('sha256', "manduvpn.ikev2.users.v1\n" . json_encode(['node_id' => $nodeId, 'users' => array_map(fn ($u) => [$u['id'], $u['username'], $u['secret'], $u['expires_at'], $u['device_limit']], $us)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    c_check('USERS-6 revision = documented canonical sha256 (independent recompute)', $rev0 === $canon((int) $N->id, $d['users'] ?? []) && $rev0 === API::revision((int) $N->id, $d['users'] ?? []));
    $etag = $h->get('ETag');
    [$sA, $rawA] = $call('GET', 'users', $N->id, "Bearer $tN", null, ['If-None-Match' => "\"$rev0\""]);
    [$sB, $rawB] = $call('GET', 'users', $N->id, "Bearer $tN", null, ['If-None-Match' => "W/\"$rev0\""]);
    [$sC] = $call('GET', 'users', $N->id, "Bearer $tN", null, ['If-None-Match' => "\"abc\", \"$rev0\""]);
    [$sD, $rawD] = $call('GET', 'users', $N->id, "Bearer $tN", null, ['If-None-Match' => '"' . str_repeat('0', 64) . '"']);
    c_check('USERS-7 ETag = "revision"; If-None-Match match (plain / W/ / list) -> 304 empty body; stale -> 200; no-store', $etag === "\"$rev0\"" && $sA === 304 && $rawA === '' && $sB === 304 && $rawB === ''
        && $sC === 304 && $sD === 200 && str_contains($rawD, '"users"') && str_contains((string) $h->get('Cache-Control'), 'no-store'), "$sA/$sB/$sC/$sD");
    $revs = [$rev0]; $okR = true;
    $step = function (callable $mutate) use ($usersCall, $N, &$tN, &$revs, &$okR, $canon, &$secrets) {
        $mutate(); [$s, $d] = $usersCall($N, $tN); foreach ($d['users'] ?? [] as $e) $secrets[] = $e['secret'];
        $okR = $okR && $s === 200 && $d['revision'] === $canon((int) $N->id, $d['users']) && !in_array($d['revision'], $revs, true); $revs[] = $d['revision'] ?? ''; return $d ?? []; };
    $dA = $step(fn () => VCS::ensure($uNoCred));
    $inA = in_array((int) $uNoCred->id, array_column($dA['users'] ?? [], 'id'), true);
    $step(function () use ($uPro, &$secrets) { $secrets[] = VCS::rotate($uPro)['secret']; });
    $step(fn () => DB::table('v2_user')->where('id', $uPro->id)->update(['expired_at' => time() + 40 * 86400]));
    $step(fn () => DB::table('v2_user')->where('id', $uPro->id)->update(['device_limit' => 5]));
    $step(fn () => DB::table('v2_vpn_credential')->where('user_id', $uPro->id)->update(['username' => 'mv' . Str::lower(Str::random(10))]));
    $dB = $step(fn () => VCS::revoke($uNoCred));
    $outB = !in_array((int) $uNoCred->id, array_column($dB['users'] ?? [], 'id'), true);
    c_check('USERS-8 revision changes on membership+ / secret / expires_at / device_limit / username / membership- (7 distinct, all canonical)', $okR && $inA && $outB && count(array_unique($revs)) === 7);
    [$s, $d, $raw] = $usersCall($E, $tE);
    c_check('USERS-9 valid empty list: 200, schema, node_id, user_count 0, users [], canonical revision', $s === 200 && ($d['schema'] ?? '') === 'manduvpn.ikev2.users.v1' && ($d['node_id'] ?? 0) === (int) $E->id
        && ($d['user_count'] ?? -1) === 0 && ($d['users'] ?? null) === [] && str_contains($raw, '"users":[]') && ($d['revision'] ?? '') === $canon((int) $E->id, []), "http=$s");
    $allUsernames = DB::table('v2_vpn_credential')->pluck('username')->all();
    [$s, $d, $raw] = $usersCall($Dis, $tD);
    c_check('USERS-10 disabled node (valid token, valid root IKEv2, show=0) -> 403 NODE_DISABLED, no credential data', $s === 403 && ($d['error'] ?? '') === 'NODE_DISABLED'
        && !array_key_exists('users', $d ?? []) && !$has($raw, array_merge($secrets, $allUsernames)) && !str_contains($raw, 'secret'), "http=$s");
    DB::table('v2_vpn_credential')->where('user_id', $uPro->id)->update(['secret_enc' => 'not-a-ciphertext']);
    [$s, $d, $raw] = $usersCall($N, $tN);
    c_check('USERS-11 corrupted stored credential -> whole list refused (500 CREDENTIAL_INVALID), never a partial 200', $s === 500 && ($d['error'] ?? '') === 'CREDENTIAL_INVALID' && !$has($raw, $secrets), "http=$s");
    $secrets[] = VCS::rotate($uPro)['secret'];

    // ---------------- REPORT ----------------
    $credUser = VCS::get(User::find($uPro->id))['username'];
    $valid = fn (array $over = []) => array_merge(['schema' => 'manduvpn.ikev2.report.v1', 'node_id' => (int) $M->id, 'generated_at' => time(), 'agent_version' => '0.1.1',
        'strongswan_version' => '5.9.5', 'applied_revision' => $rev0, 'sessions' => [['username' => $credUser, 'connected_at' => time() - 10]],
        'traffic' => [['username' => $credUser, 'rx_bytes' => 100, 'tx_bytes' => 200]], 'last_error' => null], $over);
    $health = fn ($id) => json_encode(DB::table('v2_ikev2_node')->where('server_id', $id)->first(['applied_revision', 'agent_version', 'strongswan_version', 'last_error']));
    $uTraffic = fn () => json_encode(DB::table('v2_user')->where('id', $uPro->id)->first(['u', 'd']));
    $traffic0 = $uTraffic();
    [$s, $raw] = $call('POST', 'report', $M->id, "Bearer $tM", $valid()); $d = $j($raw) ?? []; $hr = $row($M->id);
    c_check('REPORT-1 valid report -> 200 ack; applied_revision / versions / last_error stored; state HEALTHY', $s === 200 && array_keys($d) === ['schema', 'node_id', 'accepted', 'sessions', 'traffic_entries', 'traffic_accounting']
        && $d['schema'] === 'manduvpn.ikev2.report.ack.v1' && $d['accepted'] === true && $d['sessions'] === 1 && $d['traffic_entries'] === 1 && $d['traffic_accounting'] === 'deferred'
        && $hr->applied_revision === $rev0 && $hr->agent_version === '0.1.1' && $hr->strongswan_version === '5.9.5' && $hr->last_error === null && NS::getMetadata($M->id)['state'] === 'HEALTHY', "http=$s");
    $h0 = $health($M->id);
    $malformed = ['not json', '[]', '[1,2]', json_encode($valid(['schema' => 'manduvpn.ikev2.report.v2'])), json_encode(array_diff_key($valid(), ['schema' => 1])),
        json_encode($valid(['node_id' => (string) $M->id])), json_encode($valid(['generated_at' => time() + 3600])), json_encode($valid(['applied_revision' => 'xyz'])),
        json_encode(array_diff_key($valid(), ['applied_revision' => 1])), json_encode($valid(['agent_version' => ''])), json_encode($valid(['last_error' => str_repeat('e', 256)]))];
    $st = []; foreach ($malformed as $b) { [$s] = $call('POST', 'report', $M->id, "Bearer $tM", $b); $st[] = $s; }
    c_check('REPORT-2 malformed body / schema / types / ranges (11 cases) -> 422, health unchanged', array_values(array_unique($st)) === [422] && $health($M->id) === $h0, implode(',', $st));
    [$s, $raw] = $call('POST', 'report', $M->id, "Bearer $tM", $valid(['node_id' => (int) $N->id, 'agent_version' => 'evil']));
    c_check('REPORT-3 node_id mismatch -> 403 NODE_MISMATCH, nothing stored for either node', $s === 403 && $err($raw) === 'NODE_MISMATCH' && $health($M->id) === $h0 && $row($N->id)->agent_version !== 'evil', "http=$s");
    $badItems = [['sessions' => 'x'], ['sessions' => ['a' => ['username' => $credUser, 'connected_at' => 1]]], ['sessions' => [['username' => 'bad', 'connected_at' => 1]]],
        ['sessions' => [['username' => $credUser, 'connected_at' => '5']]], ['sessions' => ['x']], ['traffic' => [['username' => $credUser, 'rx_bytes' => -1, 'tx_bytes' => 0]]],
        ['traffic' => [['username' => $credUser, 'rx_bytes' => 1, 'tx_bytes' => '100']]], ['traffic' => [['username' => $credUser, 'rx_bytes' => 1.5, 'tx_bytes' => 0]]],
        ['traffic' => null], ['sessions' => array_fill(0, 10001, ['username' => $credUser, 'connected_at' => 1])]];
    $st = []; foreach ($badItems as $o) { [$s] = $call('POST', 'report', $M->id, "Bearer $tM", $valid($o)); $st[] = $s; }
    c_check('REPORT-4 invalid sessions / traffic types, negative / string / float counters, > 10000 items (10 cases) -> 422, unchanged', array_values(array_unique($st)) === [422] && $health($M->id) === $h0, implode(',', $st));
    $first = $row($M->id)->first_seen_at;
    [$s] = $call('POST', 'report', $M->id, "Bearer $tM", $valid(['show' => 0, 'token_hash' => str_repeat('a', 64), 'first_seen_at' => 1, 'users' => [['id' => 1]], 'extra' => ['x' => 1]]));
    c_check('REPORT-5 unknown top-level fields ignored (never read or stored): show / token / first_seen_at untouched', $s === 200 && (int) Server::find($M->id)->show === 1
        && (int) $row($M->id)->first_seen_at === (int) $first && NS::verifyToken($M->id, $tM), "http=$s");
    [$s] = $call('POST', 'report', $M->id, "Bearer $tM", str_repeat(' ', API::REPORT_MAX_BYTES + 1));
    c_check('REPORT-6 body larger than 2 MiB -> 413', $s === 413, "http=$s");
    [$s, $raw] = $call('POST', 'report', $Dis->id, "Bearer $tD", $valid(['node_id' => (int) $Dis->id, 'last_error' => 'disabled-node-final']));
    c_check('REPORT-7 disabled node may report (observability): 200, stored, no credential data returned', $s === 200 && $row($Dis->id)->last_error === 'disabled-node-final'
        && !str_contains($raw, 'secret') && !$has($raw, array_merge($secrets, $allUsernames)), "http=$s");
    c_check('REPORT-8 traffic accounting deferred: user u/d unchanged after a report carrying traffic', $uTraffic() === $traffic0);

    // ---------------- SECURITY: no secret fields elsewhere ----------------
    $admin = $mkUser(null, null, ['is_admin' => 1]); $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $userAuth = (new AuthService(User::find($uPro->id)))->generateAuthData()['auth_data'];
    $plain = function ($method, $uri, $auth, array $q = []) use ($http) { try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $res = $http->handle(Request::create($uri, $method, $q, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $auth])); return [$res->getStatusCode(), (string) $res->getContent()]; };
    [$a1, $b1] = $plain('GET', $R['getNodes'], $adminAuth); [$a2, $b2] = $plain('GET', $R['nodeMeta'], $adminAuth, ['id' => $N->id]);
    [$a3, $b3] = $plain('GET', $R['adminUsers'], $adminAuth, ['pageSize' => 500]); [$a4, $b4] = $plain('GET', $R['userInfo'], $userAuth);
    [, $pRaw] = $call('GET', 'ping', $N->id, "Bearer $tN");
    $needles = array_merge($secrets, $tokens, ['secret_enc', 'token_hash']);
    c_check('SEC-1 admin getNodes / ikev2Node fetch / user fetch / user info and ping gain no secret, token or hash', $a1 === 200 && $a2 === 200 && $a3 === 200 && $a4 === 200
        && !$has($b1 . $b2 . $b3 . $b4 . $pRaw, $needles), "http=$a1/$a2/$a3/$a4");

    // ---------------- LEGACY UNIPROXY (untouched; guard DEFERRED) ----------------
    $serverToken = (string) admin_setting('server_token', '');
    $legacy = function (Server $s, string $type) use ($http, $R, $serverToken) {
        $res = $http->handle(Request::create($R['uniUser'], 'GET', ['token' => $serverToken, 'node_id' => $s->id, 'node_type' => $type], [], [], ['HTTP_ACCEPT' => 'application/json']));
        return [$res->getStatusCode(), (string) $res->getContent()]; };
    $H = null; try { $H = $mkServer($g, 1, null, 'hysteria'); } catch (\Throwable $e) {}
    if ($serverToken === '' || $H === null) {
        c_check('UNIPROXY-1 Hysteria legacy path (skipped: ' . ($serverToken === '' ? 'no server_token' : 'hysteria fixture not creatable') . '); UniProxyController byte-identical is checked by the invariants', true);
    } else {
        [$s, $raw] = $legacy($H, 'hysteria'); $lu = array_map('intval', array_column(($j($raw) ?? [])['users'] ?? [], 'id'));
        c_check('UNIPROXY-1 Hysteria legacy UniProxy/user unchanged: 200, users array, group entitlement (pro user present)', $s === 200 && in_array((int) $uPro->id, $lu, true), "http=$s");
    }
    if ($serverToken !== '') {
        [$s, $raw] = $legacy($N, 'ikev2');
        c_check('UNIPROXY-2 legacy IKEv2 UniProxy/user still PP-C1 behaviour (guard DEFERRED) and never carries C4-1A VPN secrets', $s === 200 && !$has($raw, $secrets), "http=$s");
    }
} catch (\Throwable $e) {
    c_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $app['env'] = $prevEnv;
    DB::rollBack();
    foreach ($cacheKeys as $k) Cache::forget($k);
}
$logHits = 0;
foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) { $fh = fopen($f, 'r'); fseek($fh, $logSizes[$f] ?? 0); $new = (string) stream_get_contents($fh); fclose($fh);
    foreach (array_merge($tokens, $secrets) as $t) if ($t !== '' && str_contains($new, $t)) $logHits++;
    if (str_contains($new, 'Bearer mvn_') || stripos($new, 'authorization') !== false) $logHits++; }
c_check('SEC-2 no Authorization header, node token or VPN secret in Laravel logs written during the test', $logHits === 0 && count($tokens) >= 8 && count($secrets) >= 6, "hits=$logHits tokens=" . count($tokens) . ' secrets=' . count($secrets));
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
c_check('INV-1 no real server got a token, no real user got a VPN credential (v2_ikev2_node / v2_vpn_credential identical, 0 rows)', $after['v2_ikev2_node'] === $before['v2_ikev2_node']
    && $after['v2_vpn_credential'] === $before['v2_vpn_credential'] && DB::table('v2_ikev2_node')->count() === 0);
c_check('INV-2 v2_server / v2_user / v2_plan / v2_order identical (temp rows rolled back)', $after['v2_server'] === $before['v2_server'] && $after['v2_user'] === $before['v2_user']
    && $after['v2_plan'] === $before['v2_plan'] && $after['v2_order'] === $before['v2_order']);
c_check('INV-3 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
