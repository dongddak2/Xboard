<?php
// ManduVPN PP-C4-1B regression: IKEv2 node identity / token lifecycle / health storage / admin backend.
// Everything in ONE rolled-back transaction; temp servers only; no node endpoint, no Toss. Tokens/hashes never printed.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{IkeV2Node, Order, Server, User};
use App\Services\{AuthService, IkeV2NodeService as NS};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function b_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['fetch' => $route('IkeV2NodeController@fetch'), 'issue' => $route('IkeV2NodeController@issue'), 'rotate' => $route('IkeV2NodeController@rotate'),
      'revoke' => $route('IkeV2NodeController@revoke'), 'getNodes' => $route('ManageController@getNodes')];
b_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$rows = fn ($t) => Schema::hasTable($t) ? hash('sha256', json_encode(DB::table($t)->get()->map(fn ($r) => (array) $r)->all())) : 'no-table';
$serversBefore = $rows('v2_server'); $nodesBefore = $rows('v2_ikev2_node'); $vpnBefore = $rows('v2_vpn_credential');
$vpnSchema = json_encode(DB::select('PRAGMA table_info("v2_vpn_credential")'));
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$logSizes = []; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) $logSizes[$f] = filesize($f);
$tokens = [];
$hashes = fn () => array_merge(DB::table('v2_ikev2_node')->whereNotNull('token_hash')->pluck('token_hash')->all(), DB::table('v2_ikev2_node')->whereNotNull('token_prev_hash')->pluck('token_prev_hash')->all(),
    array_map(fn ($t) => hash('sha256', $t), $tokens));
$leak = function (string $body) use (&$tokens, $hashes) { if (str_contains($body, 'token_hash') || str_contains($body, 'token_prev_hash')) return 'hash field name';
    foreach ($tokens as $t) if (str_contains($body, $t)) return 'plaintext token'; foreach ($hashes() as $h) if (str_contains($body, $h)) return 'hash value'; return null; };
$msg = fn ($raw) => substr((string) (json_decode($raw, true)['message'] ?? ''), 0, 80);

DB::beginTransaction();
try {
    $mkServer = function ($show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => 'ikev2', 'name' => 'rollback-test-c41b ' . Str::random(6), 'group_ids' => [], 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return Server::find($s->id);
    };
    $mkUser = function ($admin = false) {
        $u = new User(); $u->email = 'rollback-test-c41b-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        if ($admin) $u->is_admin = 1;
        $u->save(); return User::find($u->id);
    };
    $adminAuth = (new AuthService($mkUser(true)))->generateAuthData()['auth_data'];
    $userAuth = (new AuthService($mkUser()))->generateAuthData()['auth_data'];
    $call = function ($method, $uri, array $body, $auth) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'] + ($auth ? ['HTTP_AUTHORIZATION' => $auth] : []);
        $req = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, 'POST', [], [], [], $server, json_encode($body));
        $res = $http->handle($req); $raw = (string) $res->getContent();
        return [$res->getStatusCode(), json_decode($raw, true)['data'] ?? null, $raw];
    };
    $row = fn ($id) => DB::table('v2_ikev2_node')->where('server_id', $id)->first();

    $A = $mkServer();
    [$st, $d, $raw] = $call('POST', $R['issue'], ['id' => $A->id], $adminAuth);
    $tA1 = is_array($d) ? (string) ($d['token'] ?? '') : ''; if ($tA1 !== '') $tokens[] = $tA1;
    b_check('T1 metadata created for a valid root IKEv2 server (state REGISTERED)', $st === 200 && $row($A->id) !== null && ($d['node']['state'] ?? null) === 'REGISTERED'
        && ($d['node']['server_id'] ?? null) === (int) $A->id, "http=$st " . $msg($raw));
    [$st2, , $raw2] = $call('POST', $R['issue'], ['id' => $A->id], $adminAuth); [$st3, $d3, $raw3] = $call('GET', $R['fetch'], ['id' => $A->id], $adminAuth);
    b_check('T2 issue returns the token once (2nd issue 409, fetch/metadata carry no token)', $st2 === 409 && $st3 === 200 && is_array($d3) && !array_key_exists('token', $d3)
        && $leak($raw3) === null && !array_key_exists('token', NS::getMetadata($A->id)), "issue2=$st2 fetch=$st3");
    $dbHas = function (array $needles) { foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $t) { foreach (DB::table($t->name)->cursor() as $r) {
        $j = implode("\x1f", array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) $r)); foreach ($needles as $n) if (str_contains($j, $n)) return $t->name; } } return null; };
    $inDb = $dbHas($tokens);
    b_check('T3 DB stores only sha256(token) (every table scanned for the plaintext)', $tA1 !== '' && $row($A->id)->token_hash === hash('sha256', $tA1) && $inDb === null, 'found_in=' . var_export($inDb, true));
    $many = []; for ($i = 0; $i < 20; $i++) { $many[] = NS::issue($mkServer()->id); } $tokens = array_merge($tokens, $many);
    $src = file_get_contents('/www/app/Services/IkeV2NodeService.php');
    b_check('T4 token = mvn_ + 64 hex (random_bytes(32) = 256 bits), 21 distinct, no weak RNG', !array_filter(array_merge([$tA1], $many), fn ($t) => !preg_match(NS::TOKEN_PATTERN, $t))
        && count(array_unique(array_merge([$tA1], $many))) === 21 && str_contains($src, 'bin2hex(random_bytes(32))') && !preg_match('/\b(mt_rand|rand|uniqid|str_shuffle|lcg_value)\(/', $src));
    [$sN, , $rawN] = $call('GET', $R['getNodes'], [], $adminAuth);
    $ser = json_encode(Server::find($A->id)->toArray()) . json_encode(IkeV2Node::find($A->id)->toArray()) . json_encode(IkeV2Node::find($A->id)) . json_encode(IkeV2Node::all());
    b_check('T5 ordinary serialization leaks nothing (admin server/manage/getNodes, Server + IkeV2Node toArray/toJson)', $sN === 200 && $leak($rawN) === null && $leak($ser) === null,
        "getNodes=$sN leak=" . ($leak($rawN) ?? $leak($ser) ?? 'none'));
    $metaKeys = array_keys($d3 ?? []);
    b_check('T6 metadata endpoint returns no token / hash (keys + values)', $st3 === 200 && $leak($raw3) === null && !array_intersect($metaKeys, ['token', 'token_hash', 'token_prev_hash']), implode(',', $metaKeys));
    [$st, $d, $raw] = $call('POST', $R['rotate'], ['id' => $A->id], $adminAuth);
    $tA2 = is_array($d) ? (string) ($d['token'] ?? '') : ''; if ($tA2 !== '') $tokens[] = $tA2;
    b_check('T7 rotation returns a different valid token', $st === 200 && preg_match(NS::TOKEN_PATTERN, $tA2) && $tA2 !== $tA1, "http=$st " . $msg($raw));
    $r = $row($A->id);
    b_check('T8 old current hash became the previous hash; current = hash(new)', $r->token_prev_hash === hash('sha256', $tA1) && $r->token_hash === hash('sha256', $tA2));
    b_check('T9 previous expiry = now + 24h (+-5 s), reported as previous_token_valid_until', abs((int) $r->token_prev_expires_at - (time() + 86400)) <= 5
        && ($d['node']['previous_token_valid_until'] ?? null) === (int) $r->token_prev_expires_at, 'delta=' . ((int) $r->token_prev_expires_at - time()));
    b_check('T10 new token validates against the current hash', NS::verifyToken($A->id, $tA2) === true);
    b_check('T11 old token validates during the overlap', NS::verifyToken($A->id, $tA1) === true);
    DB::table('v2_ikev2_node')->where('server_id', $A->id)->update(['token_prev_expires_at' => time() - 1]);
    b_check('T12 expired previous token fails; current still valid; wrong/empty/other-node tokens fail', NS::verifyToken($A->id, $tA1) === false && NS::verifyToken($A->id, $tA2) === true
        && NS::verifyToken($A->id, 'mvn_' . str_repeat('0', 64)) === false && NS::verifyToken($A->id, '') === false && NS::verifyToken($A->id, $many[0]) === false
        && NS::getMetadata($A->id)['previous_token_valid_until'] === null);
    $tA3 = NS::rotate($A->id); $tokens[] = $tA3;
    [$st, $d, $raw] = $call('POST', $R['revoke'], ['id' => $A->id], $adminAuth); $r = $row($A->id);
    [$stR] = $call('POST', $R['rotate'], ['id' => $A->id], $adminAuth);
    b_check('T13 revoke invalidates current + previous (hashes NULL, both tokens rejected, rotate 409, state UNREGISTERED)', $st === 200 && ($d['revoked'] ?? null) === true
        && NS::verifyToken($A->id, $tA3) === false && NS::verifyToken($A->id, $tA2) === false && $r->token_hash === null && $r->token_prev_hash === null && $r->token_prev_expires_at === null
        && $stR === 409 && NS::getMetadata($A->id)['state'] === 'UNREGISTERED' && NS::revoke($A->id) === false, "revoke=$st rotate=$stR");
    $tA4 = NS::issue($A->id); $tokens[] = $tA4;
    b_check('T13b issue after revoke gives a fresh token; old ones stay invalid', $tA4 !== $tA3 && NS::verifyToken($A->id, $tA4) && !NS::verifyToken($A->id, $tA3));
    $V = $mkServer(); DB::table('v2_server')->where('id', $V->id)->update(['type' => 'vmess']);
    [$st, , $raw] = $call('POST', $R['issue'], ['id' => $V->id], $adminAuth); [$stF] = $call('GET', $R['fetch'], ['id' => $V->id], $adminAuth);
    b_check('T14 non-IKEv2 server rejected (issue/fetch 422, no row)', $st === 422 && $stF === 422 && $row($V->id) === null, "issue=$st fetch=$stF " . $msg($raw));
    [$st, , $raw] = $call('POST', $R['issue'], ['id' => 999999999], $adminAuth); [$stX] = $call('POST', $R['issue'], [], $adminAuth);
    b_check('T15 nonexistent server rejected (422, no row); missing id 422', $st === 422 && $row(999999999) === null && $stX === 422, "http=$st missing=$stX " . $msg($raw));
    $P = $mkServer(1, $A->id);
    [$st, , $raw] = $call('POST', $R['issue'], ['id' => $P->id], $adminAuth); [$stP] = $call('POST', $R['rotate'], ['id' => $P->id], $adminAuth);
    b_check('T16 child node (parent_id set) rejected: 422, no row (root-only, as PP-C2)', $st === 422 && $stP === 422 && $row($P->id) === null, "issue=$st rotate=$stP");
    $B = $mkServer();
    $auth = []; $okA = [];
    foreach (['issue' => 'POST', 'rotate' => 'POST', 'revoke' => 'POST', 'fetch' => 'GET'] as $k => $m) {
        [$s1] = $call($m, $R[$k], ['id' => $B->id], $userAuth); [$s2] = $call($m, $R[$k], ['id' => $B->id], null);
        $auth[] = "$k=$s1/$s2"; $okA[] = $s1 === 403 && in_array($s2, [401, 403], true);
    }
    b_check('SEC non-admin (403) and unauthenticated (401/403) requests rejected, nothing created', !in_array(false, $okA, true) && $row($B->id) === null, implode(' ', $auth));
    $mass = (function () { try { (new IkeV2Node())->fill(['token_hash' => 'x']); return 'accepted'; } catch (Illuminate\Database\Eloquent\MassAssignmentException $e) { return 'rejected'; } })();
    [$st, $d] = $call('POST', $R['issue'], ['id' => $B->id, 'token_hash' => str_repeat('a', 64), 'token' => 'mvn_' . str_repeat('b', 64), 'last_seen_at' => 1, 'applied_revision' => 'x'], $adminAuth);
    $tB = is_array($d) ? (string) ($d['token'] ?? '') : ''; if ($tB !== '') $tokens[] = $tB; $rb = $row($B->id);
    b_check('SEC mass assignment: model fill() rejected; extra request fields ignored', $mass === 'rejected' && $st === 200 && $rb && $rb->token_hash === hash('sha256', $tB)
        && $rb->last_seen_at === null && $rb->applied_revision === null && $tB !== 'mvn_' . str_repeat('b', 64), "fill=$mass http=$st");
    // health storage + derived state
    $D = $mkServer(); $sD = fn () => NS::getMetadata($D->id)['state'];
    $s0 = $sD(); $tD = NS::issue($D->id); $tokens[] = $tD; $s1 = $sD();
    $hb1 = NS::recordHeartbeat($D->id, ['agent_version' => 'rb-agent', 'strongswan_version' => 'rb-5.9', 'bogus' => 'x']); $s2 = $sD(); $first = $row($D->id)->first_seen_at;
    $t100 = time() - 100;
    DB::table('v2_ikev2_node')->where('server_id', $D->id)->update(['first_seen_at' => $t100, 'last_seen_at' => $t100]);
    NS::recordHeartbeat($D->id, ['applied_revision' => 'rb-rev-1', 'last_error' => str_repeat('e', 1000)]); $s3 = $sD(); $rd = $row($D->id);
    DB::table('v2_ikev2_node')->where('server_id', $D->id)->update(['last_seen_at' => time() - NS::HEARTBEAT_TTL - 1]); $s4 = $sD();
    DB::table('v2_server')->where('id', $D->id)->update(['show' => 0]); $s5 = $sD();
    DB::table('v2_server')->where('id', $D->id)->update(['show' => 1]); NS::revoke($D->id); $s6 = $sD(); $hb2 = NS::recordHeartbeat($D->id, []);
    $seq = "$s0>$s1>$s2>$s3>$s4>$s5>$s6";
    b_check('HEALTH derived states UNREGISTERED>REGISTERED>BOOTSTRAPPING>HEALTHY>STALE>DISABLED>UNREGISTERED; heartbeat refused without active token',
        $seq === 'UNREGISTERED>REGISTERED>BOOTSTRAPPING>HEALTHY>STALE>DISABLED>UNREGISTERED' && $hb1 && !$hb2 && $first !== null, $seq);
    b_check('HEALTH first_seen_at kept, last_error truncated to 255, unknown keys ignored', (int) $rd->first_seen_at === $t100 && strlen($rd->last_error) === 255
        && $rd->agent_version === 'rb-agent' && $rd->applied_revision === 'rb-rev-1' && !property_exists($rd, 'bogus'));
    $ser2 = json_encode(IkeV2Node::all()) . json_encode(Server::whereIn('id', [$A->id, $B->id, $D->id])->get());
    b_check('SEC after all operations: no plaintext token anywhere in the DB or in model serialization', $dbHas($tokens) === null && $leak($ser2) === null);
} catch (\Throwable $e) {
    b_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$logHits = 0;
foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) { $fh = fopen($f, 'r'); fseek($fh, $logSizes[$f] ?? 0); $new = (string) stream_get_contents($fh); fclose($fh);
    foreach ($tokens as $t) if ($t !== '' && str_contains($new, $t)) $logHits++; }
b_check('T17 no plaintext token in Laravel logs written during the test', $logHits === 0 && count($tokens) >= 25, "hits=$logHits tokens_checked=" . count($tokens));
b_check('T18 no real node state modified (v2_server + v2_ikev2_node identical, temp rows gone)', $rows('v2_server') === $serversBefore && $rows('v2_ikev2_node') === $nodesBefore
    && DB::table('v2_ikev2_node')->count() === 0 && Server::where('name', 'like', 'rollback-test-c41b%')->count() === 0 && User::where('email', 'like', 'rollback-test-c41b-%')->count() === 0);
b_check('T19 C4-1A v2_vpn_credential data + schema unchanged', $rows('v2_vpn_credential') === $vpnBefore && json_encode(DB::select('PRAGMA table_info("v2_vpn_credential")')) === $vpnSchema);
b_check('T20 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
