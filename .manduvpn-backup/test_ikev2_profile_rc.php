<?php
// ManduVPN APP-BLOCK-1 regression: GET /api/v1/user/server/ikev2/profile (RC_CONTRACTS.md §4.2).
// ONE rolled-back transaction. Proves every one of the six security constraints LEAD fixed:
// auth required / own-credential-only / single node / eligible nodes only (fail closed) / secret never logged /
// values only in the single-node read.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Plan, Server, User, VpnCredential};
use App\Services\{AuthService, VpnCredentialService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$uri = $route('User\Ikev2ProfileController@profile');
$listUri = $route('User\ServerController@fetch');
t_check('setup: ikev2 profile route registered', $uri !== null, (string) $uri);
t_check('setup: v2_vpn_credential exists', Schema::hasTable('v2_vpn_credential'));
if ($uri === null) { echo "RESULT FAIL (1)\n"; exit(1); }

$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy(Schema::hasColumn($t, 'id') ? 'id' : 'user_id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = [];
foreach (['v2_server', 'v2_vpn_credential', 'v2_plan'] as $t) { $before[$t] = $rows($t); }
$usersBefore = DB::table('v2_user')->count();

DB::beginTransaction();
try {
    $req = function ($uri, array $query = [], $auth = null) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.252.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $uri . ($query ? '?' . http_build_query($query) : ''), 'GET', [], [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], (string) $res->getContent(), $res];
    };
    $mkUser = function (array $attrs = []) {
        $u = new User();
        $u->forceFill($attrs + [
            'email' => 'rollback-test-ikev2-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
            'transfer_enable' => 107374182400, 'expired_at' => time() + 86400, 'banned' => 0,
        ])->save();
        return $u;
    };
    $mkServer = function (array $attrs = []) {
        $s = new Server();
        $s->forceFill($attrs + [
            'type' => 'ikev2', 'name' => 'rollback-test-ikev2-' . Str::random(6),
            'host' => 'rbt-' . Str::lower(Str::random(6)) . '.example.invalid',
            'rate' => 1, 'port' => '500', 'server_port' => 500, 'show' => 1, 'parent_id' => null,
            'group_ids' => [1], 'tags' => [], 'route_ids' => [],
            'protocol_settings' => ['vpn_pool' => '10.66.0.0/24', 'dns' => ['1.1.1.1'], 'mtu' => 1400],
        ])->save();
        return $s;
    };
    $authOf = fn (User $u) => (new AuthService(User::find($u->id)))->generateAuthData()['auth_data'];

    $gid = (int) (DB::table('v2_server_group')->min('id') ?: 1);
    $good = $mkServer(['group_ids' => [$gid]]);
    $user = $mkUser(['group_id' => $gid]);
    $auth = $authOf($user);

    // ---- V1 constraint 1: authentication required, guests refused
    [$stAnon] = $req($uri, ['server_id' => $good->id]);
    t_check('V1 unauthenticated request is refused', $stAnon !== 200, "http=$stAnon");

    // ---- V3 happy path: address + own credential
    [$st, $j, $raw, $res] = $req($uri, ['server_id' => $good->id], $auth);
    $d = $j['data'] ?? [];
    t_check('V3 eligible node returns 200 with schema, host and fixed IKEv2 ports',
        $st === 200 && ($d['schema'] ?? null) === 'manduvpn.ikev2.profile.v1'
        && ($d['server']['host'] ?? null) === $good->host
        && ($d['server']['ports']['ike'] ?? null) === 500 && ($d['server']['ports']['nat_t'] ?? null) === 4500,
        "http=$st " . substr($raw, 0, 200));
    $mine = VpnCredentialService::get(User::find($user->id));
    t_check('V3b the credential returned is this user own row',
        ($d['credential']['username'] ?? null) === $mine['username'] && ($d['credential']['secret'] ?? null) === $mine['secret']);
    t_check('V3c username/secret match the documented format',
        (bool) preg_match(VpnCredentialService::USERNAME_PATTERN, (string) ($d['credential']['username'] ?? ''))
        && (bool) preg_match(VpnCredentialService::SECRET_PATTERN, (string) ($d['credential']['secret'] ?? '')));
    t_check('V3d secret-bearing response is marked no-store',
        str_contains(strtolower((string) $res->headers->get('Cache-Control')), 'no-store'),
        (string) $res->headers->get('Cache-Control'));
    t_check('V3e rotated_at is a unix integer', is_int($d['rotated_at'] ?? null) && ($d['rotated_at'] ?? 0) > 0);

    // ---- V7 idempotent: a second read does NOT rotate (would break already-configured devices)
    [, $j2] = $req($uri, ['server_id' => $good->id], $auth);
    t_check('V7 a second fetch returns the SAME secret (no rotation on read)',
        ($j2['data']['credential']['secret'] ?? null) === ($d['credential']['secret'] ?? null)
        && ($j2['data']['credential']['username'] ?? null) === ($d['credential']['username'] ?? null));
    t_check('V7b still exactly one credential row for this user',
        VpnCredential::where('user_id', $user->id)->count() === 1);

    // ---- V2 constraint 2: identity comes from the token only - user-ish params cannot reach another account
    $other = $mkUser(['group_id' => $gid]);
    VpnCredentialService::ensure(User::find($other->id));
    $otherCred = VpnCredentialService::get(User::find($other->id));
    foreach (['user_id' => $other->id, 'id' => $other->id, 'email' => $other->email, 'username' => $otherCred['username']] as $k => $v) {
        [$stX, $jX] = $req($uri, ['server_id' => $good->id, $k => $v], $auth);
        t_check("V2 param '$k' cannot reach another user - still returns the caller own credential",
            $stX === 200 && ($jX['data']['credential']['username'] ?? null) === $mine['username']
            && ($jX['data']['credential']['username'] ?? null) !== $otherCred['username']);
    }

    // ---- V4 constraint 4: fail closed on every ineligible node, and create nothing
    $fresh = $mkUser(['group_id' => $gid]);
    $freshAuth = $authOf($fresh);
    $hidden = $mkServer(['group_ids' => [$gid], 'show' => 0]);
    $child = $mkServer(['group_ids' => [$gid], 'parent_id' => $good->id]);
    $wrongGroup = $mkServer(['group_ids' => [$gid + 9999]]);
    $notIkev2 = $mkServer(['group_ids' => [$gid], 'type' => 'hysteria']);
    foreach ([
        'show=0' => $hidden->id,
        'parent_id NOT NULL' => $child->id,
        'group_ids does not contain the user group' => $wrongGroup->id,
        'type != ikev2' => $notIkev2->id,
        'server does not exist' => 999000111,
    ] as $label => $sid) {
        [$stN, $jN] = $req($uri, ['server_id' => $sid], $freshAuth);
        t_check("V4 ineligible node refused ($label)", $stN === 404, "http=$stN");
        t_check("V4b refusal is indistinguishable from not-found ($label)",
            $stN === 404 && (($jN['error'] ?? null) === 'IKEV2_SERVER_NOT_ELIGIBLE' || !isset($jN['error'])));
    }
    t_check('V4c a refused request created NO credential for that user',
        VpnCredential::where('user_id', $fresh->id)->count() === 0);

    // ---- V5 an inactive subscription gets nothing
    foreach (['banned' => ['banned' => 1], 'expired' => ['expired_at' => time() - 10], 'no traffic' => ['transfer_enable' => 0]] as $label => $attrs) {
        $bad = $mkUser($attrs + ['group_id' => $gid]);
        [$stB] = $req($uri, ['server_id' => $good->id], $authOf($bad));
        t_check("V5 inactive subscription refused ($label)", $stB === 403, "http=$stB");
        t_check("V5b no credential created for an inactive user ($label)", VpnCredential::where('user_id', $bad->id)->count() === 0);
    }

    // ---- V8 LEGACY tier: only the assigned server, never another eligible one
    $legacyPlan = new Plan();
    $legacyPlan->forceFill(['name' => 'rollback-test-ikev2 legacy', 'group_id' => $gid, 'service_tier' => 'legacy',
        'audience' => 'public', 'transfer_enable' => 100, 'device_limit' => 1, 'speed_limit' => null, 'show' => 0,
        'sell' => 0, 'renew' => 1, 'sort' => 9999, 'reset_traffic_method' => 0, 'capacity_limit' => null,
        'content' => null, 'prices' => ['days_30' => 45], 'prices_krw' => null])->save();
    $second = $mkServer(['group_ids' => [$gid]]);
    $legacyUser = $mkUser(['group_id' => $gid, 'plan_id' => $legacyPlan->id, 'assigned_server_id' => $good->id]);
    $legacyAuth = $authOf($legacyUser);
    [$stL] = $req($uri, ['server_id' => $good->id], $legacyAuth);
    t_check('V8 LEGACY user CAN fetch their assigned server', $stL === 200, "http=$stL");
    [$stL2] = $req($uri, ['server_id' => $second->id], $legacyAuth);
    t_check('V8b LEGACY user CANNOT fetch a different eligible server (single-server entitlement holds)',
        $stL2 === 404, "http=$stL2");
    $legacyNone = $mkUser(['group_id' => $gid, 'plan_id' => $legacyPlan->id, 'assigned_server_id' => null]);
    [$stL3] = $req($uri, ['server_id' => $good->id], $authOf($legacyNone));
    t_check('V8c LEGACY user with no assignment gets nothing (fail closed)', $stL3 === 404, "http=$stL3");

    // ---- V6 constraint 3/6: the node LIST never carries a credential
    if ($listUri) {
        [$stList, , $rawList] = $req($listUri, [], $auth);
        $leak = array_values(array_filter(['"secret"', 'secret_enc', '"password"', $mine['secret'], $mine['username']],
            fn ($needle) => $needle !== '' && str_contains($rawList, (string) $needle)));
        t_check('V6 /server/fetch carries no secret, username or password field', $leak === [], "http=$stList leak=" . count($leak));
    } else {
        t_check('V6 /server/fetch route found', false, 'route missing');
    }

    // ---- V9 constraint 5: the secret never reaches a log or an error body
    $logFile = storage_path('logs/laravel-' . date('Y-m-d') . '.log');
    $logHasSecret = is_file($logFile) && str_contains((string) @file_get_contents($logFile), $mine['secret']);
    t_check('V9 the secret does not appear in today log file', !$logHasSecret);
    [, , $rawErr] = $req($uri, ['server_id' => 999000222], $auth);
    t_check('V9b an error response body contains no secret', !str_contains($rawErr, $mine['secret']));
    [$stV] = $req($uri, [], $auth);
    t_check('V9c a missing server_id is a 422 validation error, not a 500', $stV === 422, "http=$stV");

    DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    t_check('unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

foreach ($before as $t => $sig) {
    t_check("rollback clean: $t unchanged", $rows($t) === $sig, "before=$sig after=" . $rows($t));
}
t_check('rollback clean: v2_user count unchanged', DB::table('v2_user')->count() === $usersBefore);
t_check('rollback clean: no rollback-test-ikev2 user survived', DB::table('v2_user')->where('email', 'like', 'rollback-test-ikev2-%')->count() === 0);

echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
