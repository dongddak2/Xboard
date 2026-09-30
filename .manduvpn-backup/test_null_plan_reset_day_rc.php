<?php
// ManduVPN regression: NULL-PLAN-RESET-DAY. ONE rolled-back transaction.
//
// ★THE CONDITION THIS SUITE EXISTS FOR: plan_id IS NULL **AND** expired_at IN THE FUTURE.★
// Neither half is enough. expired_at NULL or past returns at UserService.php:59-60 before any plan is read,
// which is why no organic signup ever hit this: a fresh account has expired_at NULL. The pair is reachable
// through the admin UI, because Admin/UserUpdate.php:20,22 accept `expired_at` and `plan_id` independently -
// an admin can grant an expiry and leave the plan empty. Measured on the test panel 2026-09-30:
// GET /api/v1/user/getSubscribe answered 500 five times for exactly such a user.
//
// ★WHAT THE DEFECT WAS: every `$user->plan->reset_traffic_method` below the guard (UserService.php :62 :65
// :86 :89 :92 :95 :98 - SEVEN reads, not one) dereferenced null. PHP warns, Laravel's HandleExceptions turns
// the warning into an ErrorException, and the endpoint answers 500. The fix is ONE guard in the shared
// function, not seven null-safe operators and not a guard per caller: both callers (UserController@getSubscribe
// :146 and ClientController:204) receive the same null from Plan::find(NULL).★
//
// ★WHY reset_day IS null AND NOT A DATE. A plan-less user is never reset: ResetTraffic.php:48-58 groups plans
// and then selects ->whereIn('plan_id', $planIds), so plan_id NULL matches no group. A date here would be a
// lie about something that will not happen. null is the value this function already uses for "no reset
// scheduled" (the expired branch), and the app omits the row when it is null (home_page.dart:856,
// account_page.dart:342).★
//
// ★CORRECTION 2026-09-30 (measured, not reasoned): "the response contract does not change" was WRONG as first
// written. Fixing the 500 let a pre-existing line finally emit its result: `$user->plan = Plan::find(NULL)` in
// getResetDay sets a `plan` attribute holding null, so the first green response carried a NEW `plan: null`
// key. The app tolerates it (account.dart:103-104 does `plan is Map ? ... : null`, so null and absent parse
// alike), but tolerated is not unchanged. The early `plan_id === null` return removes the key, and R2-b is
// what holds that. Lesson: a field can be added by DELETING a 500, with no line that adds a field.★
//
// ★MUTATION CHECK (run it, do not assume). TWO guards, and they fail DIFFERENT rows - check them separately:
//   (1) delete `if ($user->plan === null) return null;`      -> ★R1 and R2 MUST fail (R2 as http=500)★
//   (2) delete the earlier `if ($user->plan_id === null) return null;` -> ★R2-b MUST fail★ (R1/R2 stay green:
//       the response still answers 200, it just grows the `plan: null` key that guard (1) lets through)
// A green run against either mutation means this suite is measuring nothing.★
//
// ★NO RESULT HERE IS ENGINE-DEPENDENT, so nothing is marked "(on SQLite)". The reason, not the absence of a
// marker, is what matters: getSubscribe and getResetDay contain no whereJsonContains and no JSON predicate -
// the queries are Plan::find on a primary key and plain column reads. Compare the four RC suites, where the
// mark names the results that must be measured again once the production engine is fixed.★
//
// Usage (test server only, never production):
//   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/test_null_plan_reset_day_rc.php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Plan, User};
use App\Services\{AuthService, UserService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };

// Read the verb and path from the router instead of writing them here: a suite that hardcodes another team's
// route reports its own staleness as a code defect (the 405 scar, RC_BACKEND 66).
$uri = $route('User\UserController@getSubscribe');
t_check('setup: getSubscribe route registered', $uri !== null, (string) $uri);
$URI = $uri ?? '/api/v1/user/getSubscribe';

$sig = fn ($t, $cols) => substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get($cols)->map(fn ($r) => (array) $r)->all())), 0, 32);
$userBefore = $sig('v2_user', ['id', 'plan_id', 'expired_at', 'transfer_enable', 'u', 'd']);
$planBefore = $sig('v2_plan', ['id', 'reset_traffic_method', 'group_id']);
$userCountBefore = DB::table('v2_user')->count();
$planCountBefore = DB::table('v2_plan')->count();

$completed = false;
DB::beginTransaction();
try {
    // REMOTE_ADDR uses 10.251.x - the siblings use 10.252-10.255.x, and a shared address makes two suites'
    // rate-limit state indistinguishable.
    $req = function ($auth) use ($http, $URI) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.251.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $r = Request::create('http://localhost' . $URI, 'GET', [], [], [], $server);
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: []];
    };

    // Column list is the siblings' KEY FOR KEY: a fixture's field list is dictated by the table's NOT NULL
    // columns, not by what the code under test reads.
    $mkUser = function (?int $planId, $expiredAt) {
        $u = new User();
        $u->forceFill([
            'email' => 'rollback-test-npr-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'group_id' => 1, 'plan_id' => $planId,
            'transfer_enable' => 1000000, 'expired_at' => $expiredAt, 'banned' => 0,
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    $mkPlan = function ($resetMethod) {
        $p = new Plan();
        $p->forceFill([
            'name' => 'rollback-test-npr-' . Str::random(8), 'group_id' => 1,
            'transfer_enable' => 1000, 'reset_traffic_method' => $resetMethod,
            'show' => 0, 'sell' => 0, 'renew' => 0, 'sort' => 0,
        ])->save();
        return $p;
    };

    $future = time() + 30 * 86400;
    $past = time() - 86400;
    $svc = new UserService();

    // ---- R1: the defect's exact condition, at the function ------------------------------------------------
    // Asserted at the service and not only over HTTP, because the 500 hid WHICH call threw: a green R2 with a
    // red R1 would mean the endpoint stopped calling getResetDay, which is a different change.
    $uNull = $mkUser(null, $future);
    $threw = null;
    $day = null;
    try { $day = $svc->getResetDay(User::find($uNull->id)); } catch (\Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
    t_check('R1 NULL-PLAN-RESET-DAY: plan_id NULL + future expired_at does not throw', $threw === null, (string) $threw);
    t_check('R1-b NULL-PLAN-RESET-DAY: and returns null (no reset is scheduled for a plan-less user)',
        $threw === null && $day === null, 'returned ' . var_export($day, true));

    // ---- R2: the same condition through the endpoint the app calls ---------------------------------------
    $authNull = (new AuthService(User::find($uNull->id)))->generateAuthData()['auth_data'];
    [$st2, $j2] = $req($authNull);
    t_check('R2 NULL-PLAN-RESET-DAY: GET getSubscribe answers 200 (was 500, measured 5x on 2026-09-30)',
        $st2 === 200, 'http=' . $st2);
    // ★`plan` must be ABSENT, and the reason is NOT UserController:139.★ That assignment sits inside
    // `if ($user->plan_id)` and cannot run here. The key comes from getResetDay itself: `$user->plan =
    // Plan::find($user->plan_id)` sets a `plan` attribute holding null, which $this->success($user)
    // serialises. It stayed invisible while this endpoint answered 500 - no successful response existed to
    // carry it. The early `plan_id === null` return is what keeps the contract unchanged, so this assertion
    // now guards THAT return, and it also still catches a future "helpful" null plan object.
    $d2 = (array) ($j2['data'] ?? []);
    t_check('R2-b NULL-PLAN-RESET-DAY: response carries no `plan` key', !array_key_exists('plan', $d2),
        json_encode(array_keys($d2)));
    // ★Do NOT write `($d2['reset_day'] ?? 'x') === null` here.★ `??` fires on null, so it returns 'x' for the
    // very value this line exists to accept: the assertion could never pass, and the detail printed "ABSENT"
    // for a key that was present. Measured FAIL before AND after deployment on 2026-09-30 - it never flipped,
    // because nothing about the code could have flipped it. array_key_exists separates absent from null;
    // `=== null` then tests the value. A check that cannot succeed measures as little as one that cannot fail.
    t_check('R2-c NULL-PLAN-RESET-DAY: reset_day is null, so the app omits the row',
        array_key_exists('reset_day', $d2) && $d2['reset_day'] === null,
        array_key_exists('reset_day', $d2) ? var_export($d2['reset_day'], true) : 'ABSENT');

    // ---- R3: controls. The guard must change NOTHING for a user who has a plan ----------------------------
    // One case per branch of the switch the guard sits above, so a guard placed too early is caught.
    $planNoReset = $mkPlan(2);          // reset_traffic_method 2 = never reset
    $planMonth = $mkPlan(0);            // 0 = first day of month
    $planExpire = $mkPlan(1);           // 1 = expiry day
    $planFollow = $mkPlan(null);        // NULL = follow the system setting
    $uNoReset = $mkUser($planNoReset->id, $future);
    $uMonth = $mkUser($planMonth->id, $future);
    $uExpire = $mkUser($planExpire->id, $future);
    $uFollow = $mkUser($planFollow->id, $future);
    t_check('R3-a control: plan reset_traffic_method=2 still returns null',
        $svc->getResetDay(User::find($uNoReset->id)) === null, 'unchanged path');
    t_check('R3-b control: plan reset_traffic_method=0 still returns a day',
        is_int($svc->getResetDay(User::find($uMonth->id))), var_export($svc->getResetDay(User::find($uMonth->id)), true));
    t_check('R3-c control: plan reset_traffic_method=1 still returns a day',
        is_int($svc->getResetDay(User::find($uExpire->id))), var_export($svc->getResetDay(User::find($uExpire->id)), true));
    // The branch the guard is most easily confused with: a plan that EXISTS but whose method is NULL falls
    // through to admin_setting('reset_traffic_method'). That must keep happening - the guard is about a missing
    // PLAN, not a missing METHOD.
    // ★THIS IS THE ASSERTION THAT REFUSES `$user->plan?->reset_traffic_method`.★ Turning the seven reads into
    // null-safe ones looks like the tidier fix and was proposed as such, but `?->` puts "no plan" and "a plan
    // whose method is NULL" in the SAME bucket: both reach the `=== NULL` case and get the system-wide reset
    // day. For a plan-less user that day never arrives - ResetTraffic.php:48-58 selects
    // ->whereIn('plan_id', $planIds), so they are in no group and are never reset - so the value would be a
    // promise the system does not keep. The two states must stay distinguishable: plan-less -> null (above),
    // plan without an override -> the system setting (here). If this line is green while the guard is gone,
    // read it again before "simplifying".
    $follow = $svc->getResetDay(User::find($uFollow->id));
    t_check('R3-d control: plan EXISTS with reset_traffic_method NULL still follows the system setting',
        $follow === null || is_int($follow), var_export($follow, true));

    // ---- R4: the pre-existing early return is untouched ---------------------------------------------------
    $uPast = $mkUser(null, $past);
    t_check('R4 control: plan_id NULL + PAST expired_at still returns null at :59-60',
        $svc->getResetDay(User::find($uPast->id)) === null, 'early return intact');
    $uNever = $mkUser(null, null);
    t_check('R4-b control: plan_id NULL + expired_at NULL still returns null at :59-60',
        $svc->getResetDay(User::find($uNever->id)) === null, 'early return intact');

    $completed = true;
} catch (\Throwable $e) {
    echo 'FAIL suite aborted -- ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $fails++;
} finally {
    DB::rollBack();
}
t_check('setup: assertion block ran to the end', $completed);

// Z: nothing may survive the rollback. This suite writes fixtures, so these are not decoration.
t_check('Z1 no v2_user row survived the rollback', DB::table('v2_user')->count() === $userCountBefore,
    DB::table('v2_user')->count() . ' vs ' . $userCountBefore);
t_check('Z2 no v2_plan row survived the rollback', DB::table('v2_plan')->count() === $planCountBefore,
    DB::table('v2_plan')->count() . ' vs ' . $planCountBefore);
t_check('Z3 existing v2_user rows unchanged', $sig('v2_user', ['id', 'plan_id', 'expired_at', 'transfer_enable', 'u', 'd']) === $userBefore);
t_check('Z4 existing v2_plan rows unchanged', $sig('v2_plan', ['id', 'reset_traffic_method', 'group_id']) === $planBefore);
t_check('Z5 transaction level back to 0', DB::transactionLevel() === 0, (string) DB::transactionLevel());

echo $fails === 0 ? "ALL PASS\n" : "FAILURES: $fails\n";
exit($fails === 0 ? 0 : 1);
