<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ConsentService;
use App\Services\SignupEmailPolicyService;
use App\Services\SignupContactService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function loginWithMailLink(Request $request)
    {
        if (!(int)admin_setting('login_with_mail_link_enable')) {
            return $this->fail([404,null]);
        }
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']))) {
            return $this->fail([429 ,__('Sending frequently, please try again later')]);
        }

        $user = User::where('email', $params['email'])->first();
        if (!$user) {
            return $this->success(true);
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']), time(), 60);


        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (admin_setting('app_url')) {
            $link = admin_setting('app_url') . $redirect;
        } else {
            $link = url($redirect);
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'login',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);

        return $this->success($link);

    }

    public function register(AuthRegister $request)
    {
        if ((int)admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)admin_setting('register_limit_count', 3)) {
                return $this->fail([429,__('Register frequently, please try again after :minute minute', [
                    'minute' => admin_setting('register_limit_expire', 60)
                ])]);
            }
        }
        if ((int)admin_setting('recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(admin_setting('recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                return $this->fail([400,__('Invalid code is incorrect')]);
            }
        }
        // ManduVPN signup policy (Owner, 2026-10-13). THIS is the domain gate, and it does not depend on
        // email_whitelist_enable - that flag is 0 in live, which is why every domain was accepted. Checked
        // before any side effect: no invite code consumed, no user row, nothing cached.
        if ($policyError = SignupEmailPolicyService::validateRequest($request->input('region'), $request->input('email'))) {
            return $this->fail([422, $policyError[1]], null, $policyError[0]);
        }
        if ((int)admin_setting('email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                return $this->fail([400,__('Email suffix is not in the Whitelist')]);
            }
        }
        if ((int)admin_setting('email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return $this->fail([400,__('Gmail alias is not supported')]);
            }
        }
        if ((int)admin_setting('stop_register', 0)) {
            return $this->fail([400,__('Registration has closed')]);
        }
        // ManduVPN RC: the three mandatory signup consents (RC_CONTRACTS.md §3). This is THE gate - a client
        // that bypasses the web checkboxes and posts straight to this endpoint is refused here with 422, before
        // any side effect (no invite code is consumed, no user row is created).
        $consentErrors = ConsentService::validateRegistration($request->all());
        if ($consentErrors) {
            // Machine code goes in the existing `error` field so a frontend can localize without relying on the
            // optional content-language header; the response envelope itself is unchanged.
            return $this->fail([422, $consentErrors[0][1]], null, $consentErrors[0][0]);
        }
        if ($contactError = SignupContactService::validationError($request->all())) {
            return $this->fail([422, __('Please check your name, WeChat ID, phone and required consents')], null, $contactError);
        }
        if (!SignupContactService::available()) {
            return $this->fail([503, __('Signup contact storage is not ready')], null, 'CONTACT_STORAGE_UNAVAILABLE');
        }
        $contact = SignupContactService::normalize($request->all());
        $consentVersion = ConsentService::currentVersion();
        $consentLocale = ConsentService::normalizeLocale($request->input('consent_locale'));
        if ((int)admin_setting('invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                return $this->fail([422,__('You must use the invitation code to register')]);
            }
        }
        // ManduVPN: the email code is ALWAYS required on this route and the global email_verify flag is
        // deliberately not consulted - it is 0 in live, which is why a signup needed no code at all. The key
        // is scoped to (purpose, region, address), so a code obtained for another address or the other
        // region's flow does not verify this one, and an expired code simply is not there.
        $signupRegion = SignupEmailPolicyService::normalizeRegion($request->input('region'));
        if (empty($request->input('email_code'))) {
            return $this->fail([422,__('Email verification code cannot be empty')]);
        }
        $expectedCode = Cache::get(SignupEmailPolicyService::codeCacheKey($request->input('email'), $signupRegion));
        if ($expectedCode === null || (string)$expectedCode !== (string)$request->input('email_code')) {
            return $this->fail([400,__('Incorrect email verification code')]);
        }
        $email = $request->input('email');
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            return $this->fail([400201,__('Email already exists')]);
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        // TODO 增加过期默认值、流量告急提醒默认值 
        $user->remind_expire = admin_setting('default_remind_expire',1);
        $user->remind_traffic = admin_setting('default_remind_traffic',1);
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))
                ->where('status', 0)
                ->first();
            if (!$inviteCode) {
                // ManduVPN P1a: an explicitly entered code that does not exist (or is already used) is refused
                // with 422 before any user row exists; it used to be ignored silently unless invite_force was on.
                return $this->fail([422, __('Invalid invitation code')]);
            } else {
                $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
                if (!(int)admin_setting('invite_never_expire', 0)) {
                    // ManduVPN P1a: single-use codes are consumed by a conditional update, so two concurrent
                    // signups cannot both use the same code.
                    if (InviteCode::where('id', $inviteCode->id)->where('status', 0)->update(['status' => 1]) !== 1) {
                        return $this->fail([422, __('Invalid invitation code')]);
                    }
                }
            }
        }

        // try out
        if ((int)admin_setting('try_out_plan_id', 0)) {
            $plan = Plan::find(admin_setting('try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (admin_setting('try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        // ManduVPN RC: the user row and its consent evidence commit or roll back together (RC_CONTRACTS.md §3.2),
        // so a v2_user row can never exist without the consent it was created under.
        try {
            $created = DB::transaction(function () use ($user, $consentVersion, $consentLocale, $contact) {
                if (!$user->save()) {
                    return false;
                }
                ConsentService::record((int) $user->id, $consentVersion, (string) $consentLocale, time());
                SignupContactService::record((int) $user->id, $contact, time());
                return true;
            });
        } catch (\Throwable $e) {
            Log::error('ManduVPN RC: signup rolled back, consent evidence could not be recorded', [
                'reason' => class_basename($e),
            ]);
            return $this->fail([500,__('Register failed')]);
        }
        if (!$created) {
            return $this->fail([500,__('Register failed')]);
        }
        // The code is single-use: consume it unconditionally, and consume the SCOPED key - the one
        // register() actually verified against. This used to forget the unscoped key and only when
        // email_verify was on, so with that flag at 0 a used code stayed valid for the rest of its TTL.
        Cache::forget(SignupEmailPolicyService::codeCacheKey($request->input('email'), $signupRegion));

        $user->last_login_at = time();
        $user->save();

        // ManduVPN TR-1b: one-time free trial (admin-managed policy) for a successful self-service signup. Never fails the signup.
        try {
            \App\Services\TrialService::grantOnSignup($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ManduVPN TR-1b trial not granted: unexpected error', ['user_id' => (int) $user->id]);
        }

        if ((int)admin_setting('register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)admin_setting('register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        $data = $authService->generateAuthData();
        return $this->success($data);
    }

    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)admin_setting('password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)admin_setting('password_limit_count', 5)) {
                return $this->fail([429,__('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => admin_setting('password_limit_expire', 60)
                ])]);
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return $this->fail([400, __('Incorrect email or password')]);
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)admin_setting('password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)admin_setting('password_limit_expire', 60)
                );
            }
            return $this->fail([400, __('Incorrect email or password')]);
        }

        if ($user->banned) {
            return $this->fail([400, __('Your account has been suspended')]);
        }

        $authService = new AuthService($user);
        return $this->success($authService->generateAuthData());
    }

    public function token2Login(Request $request)
    {
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));
            
            return redirect()->to(
                admin_setting('app_url')
                    ? admin_setting('app_url') . $redirect
                    : url($redirect)
            );
        }

        if ($verify = $request->input('verify')) {
            $key = CacheKey::get('TEMP_TOKEN', $verify);
            $userId = Cache::get($key);
            
            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }
            
            $user = User::findOrFail($userId);
            
            if ($user->banned) {
                return response()->json([
                    'message' => __('Your account has been suspended')
                ], 400);
            }
            
            Cache::forget($key);
            $authService = new AuthService($user);
            
            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }
        
        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    // UNROUTED as of 2026-09-30, deliberately. Both registrations that reached this method
    // (Routes/V1/PassportRoute.php:18 and Routes/V2/PassportRoute.php:18, /api/v1 and /api/v2) were
    // removed: it mints a 60-second panel login code from a bearer token with NO auth middleware,
    // so a leaked token could be exchanged for a panel session. Do not re-register it.
    //
    // The authenticated equivalent is User\UserController@getQuickLoginUrl (Routes/V1/UserRoute.php:24,
    // 'user' middleware). It differs from this method in three ways, all of which favour it:
    //   1. it takes the identity from $request->user(), so the credential cannot arrive in the request
    //      BODY - this method's `auth_data` input puts a bearer token into request()->all();
    //   2. the sanctum guard checks expires_at, while PersonalAccessToken::findToken() below only
    //      matches the hash - verify against vendor/ before relying on that difference;
    //   3. the guard stamps last_used_at, so a call through it leaves a trace; this path leaves none.
    //
    // Routes are glob-mounted per directory (Providers/RouteServiceProvider.php:75,86), so ANY new file
    // under Http/Routes/V1 or V2 is registered automatically - a new route file can re-open this
    // without touching either PassportRoute.php.
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        
        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);
        
        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }
        
        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return $this->success($url);
    }

    public function forget(AuthForget $request)
    {
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $request->input('email'));
        $forgetRequestLimit = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) return $this->fail([429, __('Reset failed, Please try again later')]);
        if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            return $this->fail([400,__('Incorrect email verification code')]);
        }
        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            return $this->fail([400,__('This email is not registered in the system')]);
        }
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([500,__('Reset failed')]);
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        return $this->success(true);
    }
}
