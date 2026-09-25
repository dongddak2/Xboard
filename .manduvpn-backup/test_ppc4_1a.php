<?php
// ManduVPN PP-C4-1A regression: VPN credential service. Everything in ONE rolled-back transaction; no node, no Toss.
// Plaintext secrets are only compared in memory, never printed.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, User, VpnCredential};
use App\Services\{AuthService, VpnCredentialService as VCS};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Http, Route};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function v_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['adminFetch' => $route('Admin\UserController@fetch'), 'adminInfo' => $route('Admin\UserController@getUserInfoById'),
      'userInfo' => $route('V1\User\UserController@info'), 'userSub' => $route('V1\User\UserController@getSubscribe')];
v_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$tblHash = fn ($t, $where = null) => hash('sha256', json_encode(($where ? $where(DB::table($t)) : DB::table($t))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$realUsers = fn () => $tblHash('v2_user', fn ($q) => $q->where('email', 'not like', 'rollback-test-%'));
$usersBefore = $realUsers(); $plansBefore = $tblHash('v2_plan'); $ordersBefore = $tblHash('v2_order');
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$logSizes = []; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) $logSizes[$f] = filesize($f);
$plain = [];

DB::beginTransaction();
try {
    $mk = function ($admin = false) {
        $u = new User(); $u->email = 'rollback-test-v1a-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash('rollback-test-website-pass', PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        if ($admin) $u->is_admin = 1;
        $u->save(); return User::find($u->id);
    };
    $u = $mk(); $uuid0 = $u->uuid; $pw0 = $u->password;
    $c = VCS::ensure($u);
    v_check('T1 credential can be created', $c instanceof VpnCredential && (int) $c->user_id === (int) $u->id && DB::table('v2_vpn_credential')->where('user_id', $u->id)->count() === 1);
    $c2 = VCS::ensure($u);
    v_check('T2 ensure is idempotent (same row, username, ciphertext)', $c2->username === $c->username && $c2->secret_enc === $c->secret_enc && DB::table('v2_vpn_credential')->where('user_id', $u->id)->count() === 1);
    $others = []; for ($i = 0; $i < 30; $i++) { $o = $mk(); VCS::ensure($o); $others[] = $o; }
    $names = DB::table('v2_vpn_credential')->pluck('username')->all();
    v_check('T3 usernames unique (31 users) + unique index on username', count($names) === 31 && count(array_unique($names)) === 31
        && collect(DB::select('PRAGMA index_list("v2_vpn_credential")'))->contains(fn ($x) => (int) $x->unique === 1 && str_contains($x->name, 'username')));
    v_check('T4 username format mv + 10 [a-z0-9] (random, not derived from id/email/uuid)', !array_filter($names, fn ($n) => !preg_match(VCS::USERNAME_PATTERN, $n))
        && !str_contains($src0 = file_get_contents('/www/app/Services/VpnCredentialService.php'), '$user->email') && !str_contains($src0, '$user->uuid'));
    $g = VCS::get($u);
    $secrets = array_merge([$g['secret']], array_map(fn ($o) => VCS::get($o)['secret'], $others));
    $plain = $secrets; $all = implode('', $secrets);
    $src = file_get_contents('/www/app/Services/VpnCredentialService.php');
    v_check('T5 secret 24 [A-Za-z0-9], all distinct, uses random_int only', !array_filter($secrets, fn ($s) => !preg_match(VCS::SECRET_PATTERN, $s)) && count(array_unique($secrets)) === 31
        && preg_match('/[A-Z]/', $all) && preg_match('/[a-z]/', $all) && preg_match('/[0-9]/', $all)
        && str_contains($src, 'random_int(0, $max)') && !preg_match('/\b(mt_rand|rand|uniqid|str_shuffle|lcg_value)\(/', $src));
    $raw = DB::table('v2_vpn_credential')->where('user_id', $u->id)->first();
    $dump = json_encode(DB::table('v2_vpn_credential')->get()->all());
    v_check('T6 no plaintext secret in the DB (whole table dump)', !array_filter($secrets, fn ($s) => str_contains($dump, $s)));
    v_check('T7 encrypted secret decrypts correctly', Crypt::decryptString($raw->secret_enc) === $g['secret'] && $g['username'] === $c->username);
    sleep(1);
    $rot = VCS::rotate($u); $plain[] = $rot['secret'];
    $after = DB::table('v2_vpn_credential')->where('user_id', $u->id)->first();
    v_check('T8 rotate changes the secret; old one no longer stored', $rot['secret'] !== $g['secret'] && VCS::get($u)['secret'] === $rot['secret']
        && Crypt::decryptString($after->secret_enc) !== $g['secret'] && preg_match(VCS::SECRET_PATTERN, $rot['secret']));
    v_check('T9 rotate keeps the username', $rot['username'] === $c->username && $after->username === $c->username);
    v_check('T10 rotated_at advances', (int) $after->rotated_at > (int) $raw->rotated_at, "{$raw->rotated_at} -> {$after->rotated_at}");
    $rv = VCS::revoke($u);
    $rotAfterRevoke = (function () use ($u) { try { VCS::rotate($u); return 'no-exception'; } catch (\App\Exceptions\ApiException $e) { return (string) $e->getCode(); } })();
    v_check('T11 revoke deletes; get() null; rotate() 404; second revoke false', $rv === true && VCS::get($u) === null
        && DB::table('v2_vpn_credential')->where('user_id', $u->id)->count() === 0 && $rotAfterRevoke === '404' && VCS::revoke($u) === false, "rotate=$rotAfterRevoke");
    $re = VCS::ensure($u); $reg = VCS::get($u); $plain[] = $reg['secret'];
    v_check('T12 ensure after revoke = full reset (new username AND new secret)', $re->username !== $c->username && $reg['secret'] !== $rot['secret']
        && preg_match(VCS::USERNAME_PATTERN, $re->username));

    $admin = $mk(true);
    $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data']; $userAuth = (new AuthService($u))->generateAuthData()['auth_data'];
    $call = function ($uri, $auth, array $q = []) use ($http) { try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $res = $http->handle(Request::create($uri, 'GET', $q, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $auth]));
        return [$res->getStatusCode(), (string) $res->getContent()]; };
    $leak = function ($body) use ($re, &$plain) { foreach (['secret_enc', 'vpn_credential', $re->username] as $n) if (str_contains($body, $n)) return $n;
        foreach ($plain as $p) if (str_contains($body, $p)) return '<plaintext secret>'; return null; };
    [$s1, $b1] = $call($R['adminFetch'], $adminAuth, ['pageSize' => 500]); [$s2, $b2] = $call($R['adminInfo'], $adminAuth, ['id' => $u->id]);
    v_check('T13 admin user/fetch + getUserInfoById expose no secret_enc / VPN username / plaintext', $s1 === 200 && $s2 === 200 && $leak($b1) === null && $leak($b2) === null,
        "http=$s1/$s2 leak=" . ($leak($b1) ?? $leak($b2) ?? 'none'));
    [$s3, $b3] = $call($R['userInfo'], $userAuth); [$s4, $b4] = $call($R['userSub'], $userAuth);
    $ser = json_encode(User::find($u->id)->toArray()) . json_encode(VpnCredential::find($u->id)->toArray()) . json_encode(VpnCredential::find($u->id));
    v_check('T14 user info/getSubscribe + model serialization expose no secret', $s3 === 200 && $s4 === 200 && $leak($b3) === null && $leak($b4) === null
        && !str_contains($ser, 'secret_enc') && !str_contains($ser, $reg['secret']), "http=$s3/$s4 leak=" . ($leak($b3) ?? $leak($b4) ?? 'none'));
    $u2 = User::find($u->id);
    v_check('T15 existing uuid unchanged by ensure/rotate/revoke', $u2->uuid === $uuid0);
    v_check('T16 website password unchanged (same hash, still verifies)', $u2->password === $pw0 && password_verify('rollback-test-website-pass', $u2->password));
    $mass = (function () { try { (new VpnCredential())->fill(['username' => 'x', 'secret_enc' => 'y']); return 'accepted'; } catch (Illuminate\Database\Eloquent\MassAssignmentException $e) { return 'rejected'; } })();
    v_check('SEC VpnCredential rejects mass assignment', $mass === 'rejected', $mass);
} catch (\Throwable $e) {
    v_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-v1a-%')->count(); $rows = DB::table('v2_vpn_credential')->count();
v_check('rollback: no temp users, v2_vpn_credential 0 rows', $left === 0 && $rows === 0, "users=$left rows=$rows");
v_check('T17 existing users / plans / orders byte-identical', $realUsers() === $usersBefore && $tblHash('v2_plan') === $plansBefore && $tblHash('v2_order') === $ordersBefore);
v_check('T18 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
$logLeak = 0;
foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) { $fh = fopen($f, 'r'); fseek($fh, $logSizes[$f] ?? 0); $new = (string) stream_get_contents($fh); fclose($fh);
    foreach ($plain as $p) if ($p !== '' && str_contains($new, $p)) $logLeak++; }
v_check('SEC no plaintext secret written to Laravel logs during the test', $logLeak === 0, "hits=$logLeak");
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
