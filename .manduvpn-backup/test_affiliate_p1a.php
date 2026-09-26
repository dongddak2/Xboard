<?php
// ManduVPN P1a regression: register invite handling + admin inviter policy through the real HTTP kernel.
// All rows (users, invite codes, fixture orders, tokens) live in ONE transaction that is always rolled
// back. Settings are only read, never changed. Helpers use prefixed names (never Laravel helper names).
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{InviteCode, Order, User};
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Route};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function aff_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
function aff_note($n, $d) { echo "NOTE $n -- $d\n"; }
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
function aff_req($http, $method, $uri, $auth, array $body) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.254.' . random_int(1, 254) . '.' . random_int(1, 254)];
    if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
    $req = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
    $res = $http->handle($req);
    $exc = ($res->getStatusCode() >= 500 && !empty($res->exception)) ? ' EXC=' . class_basename($res->exception) . ': ' . substr($res->exception->getMessage(), 0, 140) : '';
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr($res->getContent(), 0, 180) . $exc];
}
function aff_user($admin = false) {
    $u = new User();
    $u->email = 'rollback-test-aff-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
    $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
    if ($admin) $u->is_admin = 1;
    $u->save();
    $u = User::find($u->id);
    return [$u, (new AuthService($u))->generateAuthData()['auth_data']];
}
function aff_code(int $ownerId) {   // InviteCode has no $fillable/$guarded -> property assignment, not create()
    $c = new InviteCode(); $c->user_id = $ownerId; $c->code = 'RBAFF' . Str::upper(Str::random(8)); $c->status = 0; $c->save();
    return InviteCode::find($c->id);
}
$route = function ($action) {
    $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action));
    return $r ? '/' . $r->uri() : null;
};
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$neverExpire = (int) admin_setting('invite_never_expire', 0);
$emailVerify = (int) admin_setting('email_verify', 0);
$blocked = (int) admin_setting('stop_register', 0) || (int) admin_setting('recaptcha_enable', 0) || (int) admin_setting('email_whitelist_enable', 0);
aff_note('settings', "invite_force=" . (int) admin_setting('invite_force', 0) . " invite_never_expire=$neverExpire email_verify=$emailVerify register_blocked_by_settings=" . (int) $blocked);
$adminUpdate = $route('Admin\UserController@update');
$adminSetInv = $route('Admin\UserController@setInviteUser');
$userUpdate  = $route('V1\User\UserController@update');
$inviteFetch = $route('V1\User\InviteController@fetch');
$register    = $route('Passport\AuthController@register');
aff_check('setup: routes found', $adminUpdate && $adminSetInv && $userUpdate && $inviteFetch && $register, json_encode(compact('adminUpdate', 'adminSetInv', 'userUpdate', 'inviteFetch', 'register')));

Http::preventStrayRequests();
DB::beginTransaction();
try {
    $reg = function (array $extra) use ($http, $register, $emailVerify) {
        $email = 'rollback-test-aff-reg-' . Str::lower(Str::random(10)) . '@example.invalid';
        $body = ['email' => $email, 'password' => 'Pw-' . Str::random(16), 'email_code' => ''] + $extra
            // ManduVPN RC: the three mandatory signup consents are now required by the backend gate
            // (RC_CONTRACTS.md §3). A happy-path signup fixture must supply them or every register returns 422.
            + ['consent_version' => \App\Services\ConsentService::currentVersion(), 'consent_locale' => 'ko',
               'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true];
        if ($emailVerify) { $body['email_code'] = (string) random_int(100000, 999999); Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $body['email_code'], 300); }
        [$st, $j, $raw] = aff_req($http, 'POST', $register, null, $body);
        return [$st, User::where('email', $email)->first(), $raw];
    };
    [$inviter, $inviterAuth] = aff_user();
    $code = aff_code($inviter->id);

    if ($blocked) {
        aff_check('AFF1-AFF4 register tests', false, 'registration is blocked by settings (stop_register / recaptcha / email whitelist) - cannot exercise register');
    } else {
        [$st, $nu, $raw] = $reg([]);
        aff_check('AFF1 signup without invite code -> 200, invite_user_id NULL', $st === 200 && $nu && $nu->invite_user_id === null, "http=$st $raw");
        [$st, $nu, $raw] = $reg(['invite_code' => $code->code]);
        aff_check('AFF2 signup with valid code -> 200, invite_user_id = code owner', $st === 200 && $nu && (int) $nu->invite_user_id === (int) $inviter->id, "http=$st $raw");
        $codeStatus = (int) InviteCode::find($code->id)->status;
        aff_check('AFF2b code consumption follows invite_never_expire (' . $neverExpire . ')', $codeStatus === ($neverExpire ? 0 : 1), "status=$codeStatus");
        if (!$neverExpire) {
            [$st, $nu2, $raw] = $reg(['invite_code' => $code->code]);
            aff_check('AFF2c same single-use code again -> 422, no user', $st === 422 && !$nu2, "http=$st $raw");
        }
        [$st, $bad, $raw] = $reg(['invite_code' => 'RBAFF-NO-SUCH-' . Str::upper(Str::random(6))]);
        aff_check('AFF4 unknown invite code -> 422, no user created', $st === 422 && !$bad, "http=$st $raw");
    }

    // ---- admin inviter policy ----
    [$admin, $adminAuth] = aff_user(true);
    [$target, $targetAuth] = aff_user();
    [$other] = aff_user();
    $base = fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'is_admin' => 0, 'is_staff' => 0];
    User::where('id', $target->id)->update(['invite_user_id' => $inviter->id]);
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['remarks' => 'rollback-test unrelated save']);
    aff_check('AFF5a admin save WITHOUT invite_user_email keeps invite_user_id', $st === 200 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id && User::find($target->id)->remarks === 'rollback-test unrelated save', "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => $inviter->email]);
    aff_check('AFF5b admin save with the SAME inviter email -> 200, unchanged', $st === 200 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => $other->email]);
    aff_check('AFF5c admin switch to another inviter while one is set -> 422, unchanged', $st === 422 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => $target->email]);
    aff_check('AFF3a admin self-referral via update -> 422', $st === 422 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminSetInv, $adminAuth, ['id' => $target->id, 'invite_user_email' => $target->email]);
    aff_check('AFF3b admin self-referral via setInviteUser -> 422', $st === 422, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => 'rollback-test-aff-nobody@example.invalid']);
    aff_check('AFF5d admin unknown inviter email -> 422, unchanged', $st === 422 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id, "http=$st $raw");
    // a commission-bearing relationship cannot be cleared
    $o = new Order(['user_id' => $target->id, 'plan_id' => (int) \App\Models\Plan::value('id'), 'type' => 1, 'period' => 'days_30', 'trade_no' => 'RBAFF' . Str::random(16), 'total_amount' => 1000]);
    $o->invite_user_id = $inviter->id; $o->commission_balance = 100; $o->status = 0; $o->save();
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => '']);
    aff_check('AFF5e explicit clear refused once the relationship produced commission -> 422', $st === 422 && (int) User::find($target->id)->invite_user_id === (int) $inviter->id, "http=$st $raw");
    Order::where('id', $o->id)->delete();
    [$st, , $raw] = aff_req($http, 'POST', $adminUpdate, $adminAuth, $base($target) + ['invite_user_email' => '']);
    aff_check('AFF5f explicit clear (no commission) -> 200, invite_user_id NULL', $st === 200 && User::find($target->id)->invite_user_id === null, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminSetInv, $adminAuth, ['id' => $target->id, 'invite_user_email' => $other->email]);
    aff_check('AFF5g setInviteUser sets an inviter when none is set -> 200', $st === 200 && (int) User::find($target->id)->invite_user_id === (int) $other->id, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminSetInv, $adminAuth, ['id' => $other->id, 'invite_user_email' => $target->email]);
    aff_check('AFF5h cycle (other -> target -> other) refused -> 422', $st === 422 && User::find($other->id)->invite_user_id === null, "http=$st $raw");
    [$st, , $raw] = aff_req($http, 'POST', $adminSetInv, $adminAuth, ['id' => $target->id]);
    aff_check('AFF5i setInviteUser without invite_user_email -> 422 (no implicit clear)', $st === 422 && (int) User::find($target->id)->invite_user_id === (int) $other->id, "http=$st $raw");

    // ---- a user cannot change their own inviter ----
    [$st, , $raw] = aff_req($http, 'POST', $userUpdate, $targetAuth, ['remind_expire' => 1, 'invite_user_id' => $inviter->id, 'invite_user_email' => $inviter->email]);
    aff_check('AFF5j user/update cannot change the inviter (field ignored)', $st === 200 && (int) User::find($target->id)->invite_user_id === (int) $other->id, "http=$st $raw");

    // ---- authorization / isolation ----
    [$st] = aff_req($http, 'POST', $adminUpdate, $targetAuth, $base($target) + ['invite_user_email' => '']);
    aff_check('AFF14a non-admin -> admin user/update 403', $st === 403 && (int) User::find($target->id)->invite_user_id === (int) $other->id, "http=$st");
    [$st] = aff_req($http, 'POST', $adminSetInv, $targetAuth, ['id' => $target->id, 'invite_user_email' => '']);
    aff_check('AFF14b non-admin -> admin setInviteUser 403', $st === 403, "http=$st");
    $mine = aff_code($inviter->id); $theirs = aff_code($other->id);
    [$st, $j] = aff_req($http, 'GET', $inviteFetch, $inviterAuth, []);
    $codes = collect($j['data']['codes'] ?? []);
    aff_check('AFF15 invite/fetch returns the caller\'s own code and never another user\'s', $st === 200 && $codes->contains('code', $mine->code) && !$codes->contains('code', $theirs->code),
        "http=$st codes=" . $codes->pluck('code')->implode(','));
} catch (\Throwable $e) {
    aff_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-aff%')->count() + InviteCode::where('code', 'like', 'RBAFF%')->count() + Order::where('trade_no', 'like', 'RBAFF%')->count();
aff_check('rollback: no temp users / invite codes / orders left', $left === 0, "left=$left");
aff_check('protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
