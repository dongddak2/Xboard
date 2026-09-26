<?php
// ManduVPN RC regression W-7: admin invite-code listing (OWNER_DECISIONS.md §F-4). ONE rolled-back transaction.
// Proves: the endpoint is admin-only, reports the real columns, reports usage HONESTLY in both invite modes
// (single-use vs reusable), never leaks a user secret, and writes nothing.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{InviteCode, User};
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$uri = $route('Admin\InviteCodeController@fetch');
t_check('setup: admin inviteCode/fetch route is registered', $uri !== null, (string) $uri);
t_check('setup: v2_invite_code exists', Schema::hasTable('v2_invite_code'));
if ($uri === null) { echo "RESULT FAIL (1)\n"; exit(1); }

$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = [];
foreach (['v2_invite_code', 'v2_settings'] as $t) { $before[$t] = $rows($t); }
$usersBefore = DB::table('v2_user')->count();

DB::beginTransaction();
try {
    $req = function ($method, array $query, $auth = null) use ($http, $uri) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $uri . ($query ? '?' . http_build_query($query) : ''), $method, [], [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 300)];
    };
    $mkUser = function (array $attrs = []) {
        $u = new User();
        $u->forceFill($attrs + [
            'email' => 'rollback-test-invcode-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    $setSetting = function (string $name, string $value) {
        if (DB::table('v2_settings')->where('name', $name)->exists()) {
            DB::table('v2_settings')->where('name', $name)->update(['value' => $value]);
        } else {
            DB::table('v2_settings')->insert(['name' => $name, 'value' => $value, 'group' => 'invite', 'type' => 'input', 'created_at' => now(), 'updated_at' => now()]);
        }
        try { app('cache')->flush(); } catch (\Throwable $e) {}
    };

    $issuer = $mkUser();
    $unused = new InviteCode();
    $unused->forceFill(['user_id' => $issuer->id, 'code' => 'RBIC' . strtoupper(Str::random(8)), 'status' => 0, 'pv' => 3])->save();
    $consumed = new InviteCode();
    $consumed->forceFill(['user_id' => $issuer->id, 'code' => 'RBIC' . strtoupper(Str::random(8)), 'status' => 1, 'pv' => 0])->save();

    // ---- W1 authorization: the endpoint is behind the admin middleware
    [$stAnon] = $req('GET', []);
    t_check('W1 anonymous access is refused', $stAnon !== 200, "http=$stAnon");
    $plain = $mkUser();
    $plainAuth = (new AuthService(User::find($plain->id)))->generateAuthData()['auth_data'];
    [$stUser] = $req('GET', [], $plainAuth);
    t_check('W1b a non-admin user is refused', $stUser !== 200, "http=$stUser");

    $admin = $mkUser(['is_admin' => 1]);
    $adminAuth = (new AuthService(User::find($admin->id)))->generateAuthData()['auth_data'];

    // ---- W2 single-use mode (invite_never_expire = 0, the default): usage is status and IS tracked
    $setSetting('invite_never_expire', '0');
    [$st, $j, $raw] = $req('GET', ['code' => $unused->code], $adminAuth);
    $row = $j['data'][0] ?? null;
    t_check('W2 admin can list codes and the search filter matches one row',
        $st === 200 && ($j['total'] ?? null) === 1 && $row !== null, "http=$st $raw");
    t_check('W2b the row carries the real columns (code, issuer email, status, pv, created_at)',
        $row && $row['code'] === $unused->code && $row['issuer_email'] === $issuer->email
        && $row['status'] === 0 && $row['pv'] === 3 && is_int($row['created_at']) && $row['created_at'] > 0,
        json_encode($row));
    t_check('W2c single-use mode reports usage as tracked, and an unused code as 0',
        $row && $row['usage_tracked'] === true && $row['usage_count'] === 0 && ($j['single_use_mode'] ?? null) === true);
    [, $jUsed] = $req('GET', ['code' => $consumed->code], $adminAuth);
    t_check('W2d a consumed code reports usage_count 1', ($jUsed['data'][0]['usage_count'] ?? null) === 1, json_encode($jUsed['data'][0] ?? null));

    // ---- W3 reusable mode: nothing records per-code usage, so it must report null, NOT a fake 0
    $setSetting('invite_never_expire', '1');
    [, $jRe] = $req('GET', ['code' => $unused->code], $adminAuth);
    $reRow = $jRe['data'][0] ?? null;
    t_check('W3 reusable mode reports usage_count null and usage_tracked false (no per-code counter exists)',
        $reRow && $reRow['usage_count'] === null && $reRow['usage_tracked'] === false
        && ($jRe['single_use_mode'] ?? null) === false, json_encode($reRow));
    $setSetting('invite_never_expire', '0');

    // ---- W4 no secret leaves the endpoint (RC_BACKEND.md OD-6: do not widen admin over-exposure)
    [, , $rawAll] = $req('GET', ['user_id' => $issuer->id], $adminAuth);
    $leaked = array_values(array_filter(['token', 'uuid', 'password', 'password_salt', 'subscribe_url'],
        fn ($k) => str_contains($rawAll, '"' . $k . '"')));
    t_check('W4 the response carries no token / uuid / password / subscribe_url field', $leaked === [], implode(',', $leaked));

    // ---- W5 the filters are validated, not injected
    [$stBad] = $req('GET', ['status' => 7], $adminAuth);
    t_check('W5 an out-of-range status filter is refused with 422', $stBad === 422, "http=$stBad");
    [$stBad2] = $req('GET', ['pageSize' => 5000], $adminAuth);
    t_check('W5b an oversized pageSize is refused with 422', $stBad2 === 422, "http=$stBad2");

    DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    t_check('unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

foreach ($before as $t => $sig) {
    t_check("rollback clean: $t unchanged", $rows($t) === $sig, "before=$sig after=" . $rows($t));
}
t_check('rollback clean: v2_user count unchanged', DB::table('v2_user')->count() === $usersBefore);
t_check('rollback clean: no rollback-test-invcode user survived', DB::table('v2_user')->where('email', 'like', 'rollback-test-invcode-%')->count() === 0);

echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
