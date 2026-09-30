<?php
// ManduVPN regression: QUICKLOGIN-UNAUTH-REMOVED (WS2). ONE rolled-back transaction.
//
// ★THE CONDITION THIS SUITE EXISTS FOR: a bearer token could be exchanged for a PANEL LOGIN SESSION with no
// auth middleware in the chain. Passport\AuthController@getQuickLoginUrl reads the credential itself -
// `$request->input('auth_data') ?? $request->header('authorization')` (AuthController.php:330 after this
// change) - and hands back a 60-second TEMP_TOKEN code plus the /#/login?verify=<code> URL. Anyone holding a
// leaked token could therefore mint a panel session without ever passing a middleware.★
//
// ★WHAT WAS REMOVED, AND WHY «TWO» IS THE WHOLE POINT: the method had TWO registrations, not one -
// Routes/V1/PassportRoute.php:18 (/api/v1/passport/auth/getQuickLoginUrl) and Routes/V2/PassportRoute.php:18
// (/api/v2/...). Both pointed at the SAME controller method. Fixing one leaves the other open, so R1 below
// enumerates the route table and asserts the ACTION is absent rather than probing a single URL.★
//
// ★WHY REMOVAL AND NOT «ADD THE user MIDDLEWARE»: the handler resolves the identity itself from `auth_data`,
// which is a request BODY field. Middleware in front of it would authenticate the header while the handler
// kept reading the body, and the credential would still travel inside request()->all() (which is what
// Logger/MysqlLoggerHandler writes into v2_log.data if that channel is ever switched on). The authenticated
// twin User\UserController@getQuickLoginUrl (Routes/V1/UserRoute.php:24, 'user' middleware) takes the
// identity from $request->user() and has no body channel at all, so the duplicate implementation goes and the
// canonical one stays. Owner's method for WS2 was "reuse the existing auth path, no duplicate implementation".★
//
// ★THE TWO IMPLEMENTATIONS ARE NOT EQUIVALENT, AND EVERY DIFFERENCE FAVOURS THE ONE THAT STAYS:
//   (1) CHANNEL - Passport accepts the token in the POST body (`auth_data`); the sanctum guard reads the
//       Authorization header only. R8 asserts the body channel is closed.
//   (2) EXPIRY  - Passport called PersonalAccessToken::findToken($token)?->tokenable with NO expires_at
//       check; the guard checks it. Tokens are minted with now()->addYear() (AuthService.php:25), so expiry
//       is real. R7 measures this: an EXPIRED token must not mint a code. ★findToken()'s own behaviour is
//       asserted here rather than read - this tree has no vendor/, so the claim "findToken does not filter
//       expiry" is UNVERIFIED BY ME and R7 is what decides it.★
//   (3) TRACE   - the guard stamps last_used_at; findToken() alone does not. The removed path was a
//       token-use channel that left no forensic record. Nothing below measures this; it is recorded because
//       it is why the removal also closes a blind spot, not only an access path.
// Below the identity step the two methods are line-for-line the same (guid -> TEMP_TOKEN -> 60s -> URL), so
// nothing else changes shape.★
//
// ★WHAT IS DELIBERATELY UNTOUCHED: token2Login, BOTH registrations (V1/PassportRoute.php:16 and
// V2/PassportRoute.php:16). It is the mail-link login and the code-exchange half of the authenticated twin -
// it consumes a TEMP_TOKEN, it does not mint one from a bearer token. R9 asserts both are still routed, so
// that a later "tidy the passport routes" commit cannot quietly take them with it.★
//
// ★MUTATION CHECK (run it, do not assume): put back
//     $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
// in Routes/V1/PassportRoute.php and re-run. ★R1 and R3 MUST fail★ (R3 as http=200 with a url in the body).
// Put it back in the V2 file instead and ★R1 and R4 MUST fail★. A green run with either line restored means
// this suite is measuring nothing. R2, R5, R6, R9 MUST STAY GREEN under both mutations - they describe the
// authenticated path, which this change does not touch.★
//
// ★SIDE EFFECTS, STATED BECAUSE R6 EXERCISES A SUCCESS PATH:
//   - every row this suite creates lives inside ONE transaction that is rolled back; R10 re-checks the table
//     signatures afterwards.
//   - R6 additionally writes ONE cache key (TEMP_TOKEN/<guid>, 60s TTL). Cache is NOT transactional, so that
//     key outlives the rollback. It is inert: it holds the throwaway user's id, and that user row is gone
//     after the rollback, so token2Login's User::findOrFail would 404 on it.
//   - ★RUN THIS ON THE TEST PANEL ONLY. Do not run it against production, and do not call the live
//     endpoints by hand: the success path creates a real code and a real token row.★
//   - no outbound HTTP: Http::preventStrayRequests() is armed before the first request.★
//
// ★NOT MEASURED HERE (and not convertible to PASS by this file): whether :7001 can be reached directly,
// bypassing nginx. That is a host question, it belongs to INFRA/QA, and no assertion below says anything
// about it. This suite measures the APPLICATION level only, which is the level Owner scoped WS2 to.★
//
// ★THIS FILE WAS NOT EXECUTED BY ITS AUTHOR: the backend agent's tree has no php, no vendor/ and no
// tests/ directory, so every R below is UNRUN at the time of the commit. Treat a missing run as missing,
// not as green.★
//
// Run:  php .manduvpn-backup/test_quicklogin_unauth_removed_rc.php
// Exit: 0 = all PASS, 1 = at least one FAIL.

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }

Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Every route whose action ends with the given suffix, as '/uri' => [middleware].
$actions = function ($suffix) {
    $out = [];
    foreach (Route::getRoutes()->getRoutes() as $r) {
        if (str_ends_with($r->getActionName(), $suffix)) {
            $out['/' . $r->uri()] = $r->gatherMiddleware();
        }
    }
    return $out;
};

// ---- R1/R2: the route table itself. Asserted on the ACTION, so BOTH api versions are covered at once.
$unauth = $actions('Passport\AuthController@getQuickLoginUrl');
t_check('R1 no route reaches Passport\AuthController@getQuickLoginUrl',
    count($unauth) === 0, 'found: ' . json_encode(array_keys($unauth)));

$authed = $actions('User\UserController@getQuickLoginUrl');
t_check('R2-a exactly one route reaches User\UserController@getQuickLoginUrl',
    count($authed) === 1, 'found: ' . json_encode(array_keys($authed)));
$AUTHED_URI = count($authed) === 1 ? array_key_first($authed) : '/api/v1/user/getQuickLoginUrl';
t_check('R2-b that route carries the user middleware',
    count($authed) === 1 && in_array('user', reset($authed), true),
    json_encode($authed));

// ---- R9: token2Login untouched, both versions.
$t2l = $actions('Passport\AuthController@token2Login');
t_check('R9 token2Login still routed on both api versions', count($t2l) === 2, json_encode(array_keys($t2l)));

// ---- R11: the seven registrations I did NOT remove are still there, on both api versions.
// This edit deleted a line from each PassportRoute.php with a script, and the author could not run php -l
// (no php in that tree). A syntax error or a stray deletion there would take EVERY passport route with it,
// and R1 would still pass - an empty route table satisfies "the action is absent". R11 is what separates
// "removed one route" from "broke the file".
$survivors = ['Passport\AuthController@register', 'Passport\AuthController@login',
    'Passport\AuthController@token2Login', 'Passport\AuthController@forget',
    'Passport\AuthController@loginWithMailLink', 'Passport\CommController@sendEmailVerify',
    'Passport\CommController@pv'];
foreach ($survivors as $a) {
    $got = $actions($a);
    t_check('R11 ' . $a . ' still routed twice', count($got) === 2, json_encode(array_keys($got)));
}

$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(
    DB::table($t)->orderBy('id')->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$userBefore = $sig('v2_user', ['id', 'email', 'banned']);
$patBefore = $sig('personal_access_tokens', ['id', 'tokenable_id', 'expires_at']);
$userCountBefore = DB::table('v2_user')->count();
$patCountBefore = DB::table('personal_access_tokens')->count();

$completed = false;
DB::beginTransaction();
try {
    // REMOTE_ADDR uses 10.249.x - the sibling suites use 10.251-10.255.x, and a shared address makes two
    // suites' rate-limit state indistinguishable.
    $post = function ($uri, $auth = null, $body = []) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.249.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth !== null) $server['HTTP_AUTHORIZATION'] = $auth;
        $res = $http->handle(Request::create('http://localhost' . $uri, 'POST', $body, [], [], $server));
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: []];
    };
    // A 'data' that is a string containing verify= is the ONLY shape that means a code was minted. Anything
    // else - error body, empty data, redirect - is not a mint, so the test asks for the string.
    $minted = fn ($body) => is_string($body['data'] ?? null) && str_contains($body['data'], 'verify=');

    $mkUser = function () {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-qlu-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'group_id' => 1, 'plan_id' => null,
            'transfer_enable' => 1000000, 'expired_at' => null, 'banned' => 0,
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };

    $user = $mkUser();
    // createToken returns 'id|plain'; the header carries the plain half, exactly as AuthService formats it.
    $live = $user->createToken(Str::random(20), ['*'], now()->addYear());
    $livePlain = explode('|', $live->plainTextToken)[1] ?? $live->plainTextToken;

    $dead = $user->createToken(Str::random(20), ['*'], now()->addYear());
    $deadPlain = explode('|', $dead->plainTextToken)[1] ?? $dead->plainTextToken;
    $dead->accessToken->forceFill(['expires_at' => now()->subDay()])->save();

    // ---- R3/R4: the two removed URLs, unauthenticated AND with a valid token in either channel.
    foreach (['R3' => '/api/v1/passport/auth/getQuickLoginUrl',
              'R4' => '/api/v2/passport/auth/getQuickLoginUrl'] as $tag => $uri) {
        [$s1, $b1] = $post($uri);
        t_check("$tag-a $uri unauthenticated does not mint", $s1 !== 200 && !$minted($b1), "http=$s1");
        [$s2, $b2] = $post($uri, 'Bearer ' . $livePlain);
        t_check("$tag-b $uri with a valid header token does not mint", $s2 !== 200 && !$minted($b2), "http=$s2");
        [$s3, $b3] = $post($uri, null, ['auth_data' => 'Bearer ' . $livePlain]);
        t_check("$tag-c $uri with the token in the body does not mint", $s3 !== 200 && !$minted($b3), "http=$s3");
    }

    // ---- R5 (Owner matrix 1): unauthenticated on the surviving route is refused.
    [$s5, $b5] = $post($AUTHED_URI);
    t_check('R5 authed route unauthenticated is refused', $s5 === 403 && !$minted($b5), "http=$s5");

    // ---- R6 (Owner matrix 2): authenticated mints a code. THIS is the success path; see SIDE EFFECTS.
    [$s6, $b6] = $post($AUTHED_URI, 'Bearer ' . $livePlain);
    t_check('R6 authed route with a live token mints a code', $s6 === 200 && $minted($b6), "http=$s6");

    // ---- R7 (Owner matrix 3): an expired token is refused. The removed path had no expiry check.
    [$s7, $b7] = $post($AUTHED_URI, 'Bearer ' . $deadPlain);
    t_check('R7 expired token does not mint', $s7 !== 200 && !$minted($b7), "http=$s7");

    // ---- R8: the body channel is closed everywhere - no header, token in auth_data only.
    [$s8, $b8] = $post($AUTHED_URI, null, ['auth_data' => 'Bearer ' . $livePlain]);
    t_check('R8 body-only credential does not mint on any route', $s8 !== 200 && !$minted($b8), "http=$s8");

    $completed = true;
} catch (\Throwable $e) {
    t_check('suite ran to completion', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    DB::rollBack();
}
t_check('suite reached the end without throwing', $completed);

// ---- R10: the rollback really rolled back. A suite that leaves rows behind is not re-runnable.
t_check('R10-a v2_user unchanged', $sig('v2_user', ['id', 'email', 'banned']) === $userBefore);
t_check('R10-b personal_access_tokens unchanged',
    $sig('personal_access_tokens', ['id', 'tokenable_id', 'expires_at']) === $patBefore);
t_check('R10-c row counts unchanged',
    DB::table('v2_user')->count() === $userCountBefore
    && DB::table('personal_access_tokens')->count() === $patCountBefore,
    'v2_user ' . DB::table('v2_user')->count() . "/$userCountBefore, pat "
    . DB::table('personal_access_tokens')->count() . "/$patCountBefore");

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAIL\n";
exit($fails === 0 ? 0 : 1);
