<?php
// ManduVPN RC regression: referral reward POINTS (RC_CONTRACTS.md §4). ONE rolled-back transaction.
// Proves: accrual on the invitee's FIRST completed PAID order only, exactly once per invitee (DB-enforced),
// currency-independent (KRW and CNY grant the same), and that cash columns are never touched.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, User};
use App\Services\{AuthService, OrderService, PointsService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = [
    'userPoints' => $route('User\PointsController@fetch'),
    'policy' => $route('Admin\PointsController@fetchPolicy'),
    'policySave' => $route('Admin\PointsController@savePolicy'),
    'rewards' => $route('Admin\PointsController@fetchRewards'),
    'adminUser' => $route('Admin\PointsController@fetchUser'),
    'adjust' => $route('Admin\PointsController@adjust'),
    'revoke' => $route('Admin\PointsController@revokeReward'),
];
t_check('setup: routes found (user points + 6 admin points endpoints)', !in_array(null, $R, true), json_encode($R));
t_check('setup: v2_referral_reward and v2_points_ledger tables exist', Schema::hasTable('v2_referral_reward') && Schema::hasTable('v2_points_ledger'));

$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = [];
foreach (['v2_referral_reward', 'v2_points_ledger', 'v2_settings', 'v2_order', 'v2_plan', 'v2_payment'] as $t) {
    if (Schema::hasTable($t)) $before[$t] = $rows($t);
}
$usersBefore = DB::table('v2_user')->count();
// Cash columns across the whole table must be byte-identical afterwards: points are not cash.
$cashSig = fn () => substr(hash('sha256', json_encode(DB::table('v2_user')->orderBy('id')->get(['id', 'balance', 'commission_balance'])->map(fn ($r) => (array) $r)->all())), 0, 32);
$cashBefore = $cashSig();

DB::beginTransaction();
try {
    $req = function ($method, $uri, array $body, $auth = null) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.254.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $uri = 'http://localhost' . $uri;
        $r = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 300)];
    };
    $mkUser = function (array $attrs = []) {
        $u = new User();
        $u->forceFill($attrs + [
            'email' => 'rollback-test-points-' . Str::lower(Str::random(10)) . '@example.invalid',
            'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32),
            'remind_expire' => 0, 'remind_traffic' => 0, 'balance' => 0, 'commission_balance' => 0,
        ])->save();
        return $u;
    };
    $setPolicy = function (array $p) {
        foreach ($p as $k => $v) {
            $name = PointsService::KEYS[$k];
            if (DB::table('v2_settings')->where('name', $name)->exists()) {
                DB::table('v2_settings')->where('name', $name)->update(['value' => (string) $v]);
            } else {
                DB::table('v2_settings')->insert(['name' => $name, 'value' => (string) $v, 'group' => 'manduvpn', 'type' => 'input', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    };
    $plan = new Plan();
    $plan->forceFill(['name' => 'rollback-test-points plan', 'group_id' => (int) (DB::table('v2_server_group')->min('id') ?: 1),
        'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false, 'renew' => true,
        'sort' => 9999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['days_30' => 45], 'prices_krw' => ['days_30' => 9000]])->save();
    // Completes an order through the real OrderService::open() path.
    $complete = function (User $u, int $totalAmount, string $currency = 'CNY') use ($plan) {
        $o = new Order();
        $o->forceFill(['user_id' => $u->id, 'plan_id' => $plan->id, 'period' => 'days_30',
            'trade_no' => 'RBPT' . strtoupper(Str::random(16)), 'total_amount' => $totalAmount, 'currency' => $currency,
            'status' => Order::STATUS_PROCESSING, 'type' => Order::TYPE_NEW_PURCHASE, 'paid_at' => time()])->save();
        (new OrderService(Order::find($o->id)))->open();
        return Order::find($o->id);
    };

    // ---- P0 default policy is OFF: nothing is granted until an admin enables it
    $setPolicy(['enable' => '0', 'referral_reward' => '1000', 'expire_days' => '']);
    $inviter0 = $mkUser();
    $invitee0 = $mkUser(['invite_user_id' => $inviter0->id]);
    $complete($invitee0, 4500);
    t_check('P0 policy disabled: no reward, no ledger row', DB::table('v2_referral_reward')->where('invitee_user_id', $invitee0->id)->count() === 0
        && PointsService::balanceOf((int) $inviter0->id) === 0);

    // ---- P1 enabled: first completed PAID order grants exactly the policy amount to the INVITER
    $setPolicy(['enable' => '1', 'referral_reward' => '1500', 'expire_days' => '']);
    $inviter = $mkUser();
    $invitee = $mkUser(['invite_user_id' => $inviter->id]);
    $o1 = $complete($invitee, 4500);
    $rw = DB::table('v2_referral_reward')->where('invitee_user_id', $invitee->id)->first();
    t_check('P1 first paid order grants a reward row to the inviter with the policy amount',
        $rw && (int) $rw->inviter_user_id === (int) $inviter->id && (int) $rw->points === 1500
        && $rw->status === 'granted' && (int) $rw->trigger_order_id === (int) $o1->id
        && is_numeric($rw->granted_at) && abs((int) $rw->granted_at - time()) < 300 && $rw->expires_at === null,
        $rw ? json_encode(['p' => $rw->points, 'st' => $rw->status]) : 'no row');
    t_check('P1 inviter balance is 1500 P and the ledger row is typed referral_reward',
        PointsService::balanceOf((int) $inviter->id) === 1500
        && DB::table('v2_points_ledger')->where('user_id', $inviter->id)->where('type', 'referral_reward')->where('amount', 1500)->where('source_id', $rw->id)->count() === 1);
    t_check('P1 the INVITEE earns nothing for their own purchase', PointsService::balanceOf((int) $invitee->id) === 0);
    t_check('P1 cash columns untouched: inviter balance and commission_balance are still 0',
        (int) User::find($inviter->id)->balance === 0 && (int) User::find($inviter->id)->commission_balance === 0);

    // ---- P2 exactly once per invitee: a second (and third) order grants nothing more
    $complete($invitee, 4500);
    $complete($invitee, 9000);
    t_check('P2 exactly once: further orders by the same invitee add no reward and no points',
        DB::table('v2_referral_reward')->where('invitee_user_id', $invitee->id)->count() === 1
        && PointsService::balanceOf((int) $inviter->id) === 1500);
    // direct double-grant attempt must also be refused by the UNIQUE index
    [$policy] = PointsService::policy();
    $second = PointsService::grant((int) $inviter->id, (int) $invitee->id, null, $policy, time());
    t_check('P2b a direct duplicate grant() returns false and writes nothing (UNIQUE invitee_user_id)',
        $second === false && DB::table('v2_referral_reward')->where('invitee_user_id', $invitee->id)->count() === 1
        && PointsService::balanceOf((int) $inviter->id) === 1500);

    // ---- P3 currency independence: a KRW order grants the same flat amount as a CNY order
    $inviterK = $mkUser();
    $inviteeK = $mkUser(['invite_user_id' => $inviterK->id]);
    $complete($inviteeK, 9000, 'KRW');
    t_check('P3 a KRW order grants the same flat points as a CNY order (points are currency-independent)',
        PointsService::balanceOf((int) $inviterK->id) === 1500);

    // ---- P3c OD-3 (OWNER_DECISIONS.md §F-2): the amount is the INVITER's current plan's 30-day CNY price.
    // Note every inviter above has no plan_id, which is exactly why they all earn the flat fallback: those
    // assertions are the fallback branch and these are the plan branch.
    $inviterP = $mkUser(['plan_id' => $plan->id]);           // plan prices: days_30 = 45 YUAN, prices_krw = 9000 WON
    $inviteeP = $mkUser(['invite_user_id' => $inviterP->id]);
    $complete($inviteeP, 4500);
    t_check('P3c inviter on a 30d 45-yuan plan earns 45 P, not the flat 1500 fallback',
        (int) DB::table('v2_referral_reward')->where('invitee_user_id', $inviteeP->id)->value('points') === 45
        && PointsService::balanceOf((int) $inviterP->id) === 45,
        'points=' . DB::table('v2_referral_reward')->where('invitee_user_id', $inviteeP->id)->value('points'));
    t_check('P3d the 45 basis is the CNY price, NOT prices_krw (9000 WON would be a 200x reward)',
        PointsService::balanceOf((int) $inviterP->id) !== 9000);

    // A legacy plan prices its 30 days under 'monthly', and yuan may carry decimals.
    $planLegacy = new Plan();
    $planLegacy->forceFill(['name' => 'rollback-test-points legacy plan', 'group_id' => (int) $plan->group_id,
        'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false,
        'renew' => true, 'sort' => 9999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['monthly' => 29.9], 'prices_krw' => null])->save();
    $inviterL = $mkUser(['plan_id' => $planLegacy->id]);
    $inviteeL = $mkUser(['invite_user_id' => $inviterL->id]);
    $complete($inviteeL, 4500);
    t_check('P3e a legacy plan priced under "monthly" is read too, and 29.9 rounds to 30 P',
        PointsService::balanceOf((int) $inviterL->id) === 30,
        'balance=' . PointsService::balanceOf((int) $inviterL->id));

    // A plan with no 30-day price at all (360-day only) must fall back, never grant 0.
    $planNo30 = new Plan();
    $planNo30->forceFill(['name' => 'rollback-test-points no30 plan', 'group_id' => (int) $plan->group_id,
        'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false,
        'renew' => true, 'sort' => 9999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['days_360' => 360], 'prices_krw' => null])->save();
    $inviterN = $mkUser(['plan_id' => $planNo30->id]);
    $inviteeN = $mkUser(['invite_user_id' => $inviterN->id]);
    $complete($inviteeN, 4500);
    t_check('P3f a plan with no 30-day price falls back to the flat policy amount (1500), never 0',
        PointsService::balanceOf((int) $inviterN->id) === 1500,
        'balance=' . PointsService::balanceOf((int) $inviterN->id));

    // A deleted plan row must not stop the grant either.
    $inviterD = $mkUser(['plan_id' => 999000000]);
    $inviteeD = $mkUser(['invite_user_id' => $inviterD->id]);
    $complete($inviteeD, 4500);
    t_check('P3g an inviter whose plan row no longer exists still earns the fallback',
        PointsService::balanceOf((int) $inviterD->id) === 1500);

    t_check('P3h referralPointsFor is pure: it wrote nothing to the inviter cash columns',
        (int) User::find($inviterP->id)->balance === 0 && (int) User::find($inviterP->id)->commission_balance === 0
        && (int) User::find($inviterL->id)->commission_balance === 0);

    // ---- P4 a zero-amount order is not a paid order
    $inviterZ = $mkUser();
    $inviteeZ = $mkUser(['invite_user_id' => $inviterZ->id]);
    $complete($inviteeZ, 0);
    t_check('P4 a 0-amount (fully discounted) order grants nothing',
        DB::table('v2_referral_reward')->where('invitee_user_id', $inviteeZ->id)->count() === 0
        && PointsService::balanceOf((int) $inviterZ->id) === 0);

    // ---- P5 no inviter, and self-referral, grant nothing
    $solo = $mkUser();
    $complete($solo, 4500);
    t_check('P5 a user with no inviter grants nothing', DB::table('v2_referral_reward')->where('invitee_user_id', $solo->id)->count() === 0);
    $selfRef = $mkUser();
    $selfRef->forceFill(['invite_user_id' => $selfRef->id])->save();
    $complete($selfRef, 4500);
    t_check('P5b self-referral grants nothing', DB::table('v2_referral_reward')->where('invitee_user_id', $selfRef->id)->count() === 0
        && PointsService::balanceOf((int) $selfRef->id) === 0);

    // ---- P6 expiry: an expired accrual leaves the balance, but stays visible as expired
    $setPolicy(['enable' => '1', 'referral_reward' => '700', 'expire_days' => '30']);
    $inviterE = $mkUser();
    $inviteeE = $mkUser(['invite_user_id' => $inviterE->id]);
    $complete($inviteeE, 4500);
    $rwE = DB::table('v2_referral_reward')->where('invitee_user_id', $inviteeE->id)->first();
    t_check('P6 expire_days=30 stamps expires_at on both the reward and the ledger row',
        $rwE && (int) $rwE->expires_at === (int) $rwE->granted_at + 30 * 86400
        && (int) DB::table('v2_points_ledger')->where('source_id', $rwE->id)->where('type', 'referral_reward')->value('expires_at') === (int) $rwE->expires_at);
    t_check('P6b balance counts it now', PointsService::balanceOf((int) $inviterE->id) === 700);
    $future = (int) $rwE->expires_at + 1;
    t_check('P6c balance excludes it once expired, and expiredTotalOf reports it',
        PointsService::balanceOf((int) $inviterE->id, $future) === 0 && PointsService::expiredTotalOf((int) $inviterE->id, $future) === 700);

    // ---- P7 user API
    $auth = (new AuthService(User::find($inviter->id)))->generateAuthData()['auth_data'];
    [$stU, $jU] = $req('GET', $R['userPoints'], [], $auth);
    $d = $jU['data'] ?? [];
    t_check('P7 GET user/points returns unit P, the balance, the ledger and the reward list',
        $stU === 200 && ($d['unit'] ?? null) === 'P' && ($d['balance'] ?? null) === 1500
        && count($d['ledger'] ?? []) === 1 && count($d['referral_rewards'] ?? []) === 1
        && ($d['policy']['enabled'] ?? null) === true, "http=$stU");
    [$stNoAuth] = $req('GET', $R['userPoints'], []);
    t_check('P7b user/points requires authentication', $stNoAuth !== 200, "http=$stNoAuth");

    // ---- P8 admin API
    $admin = $mkUser(['is_admin' => 1]);
    $adminAuth = (new AuthService(User::find($admin->id)))->generateAuthData()['auth_data'];
    [$stP, $jP] = $req('GET', $R['policy'], [], $adminAuth);
    t_check('P8 admin pointsPolicy fetch reports the policy, unit P and spending_implemented=false',
        $stP === 200 && ($jP['data']['policy']['referral_reward'] ?? null) === 700
        && ($jP['data']['unit'] ?? null) === 'P' && ($jP['data']['spending_implemented'] ?? null) === false, "http=$stP");
    [$stBad] = $req('POST', $R['policySave'], ['enable' => true, 'referral_reward' => 0, 'expire_days' => ''], $adminAuth);
    t_check('P8b policy save refuses referral_reward=0 with 422', $stBad === 422, "http=$stBad");
    [$stBad2] = $req('POST', $R['policySave'], ['enable' => true, 'referral_reward' => 100], $adminAuth);
    t_check('P8c policy save refuses a missing field with 422', $stBad2 === 422, "http=$stBad2");
    [$stOk, $jOk] = $req('POST', $R['policySave'], ['enable' => true, 'referral_reward' => 800, 'expire_days' => ''], $adminAuth);
    t_check('P8d policy save accepts a valid policy', $stOk === 200 && ($jOk['data']['policy']['referral_reward'] ?? null) === 800, "http=$stOk");
    t_check('P8e raising the amount does NOT rewrite an existing reward (snapshot semantics)',
        (int) DB::table('v2_referral_reward')->where('invitee_user_id', $invitee->id)->value('points') === 1500);
    [$stR, $jR] = $req('GET', $R['rewards'], ['inviter_user_id' => $inviter->id], $adminAuth);
    t_check('P8f admin reward list filters by inviter and joins only id/email',
        $stR === 200 && count($jR['data'] ?? []) === 1
        && ($jR['data'][0]['invitee_email'] ?? null) === $invitee->email
        && !array_key_exists('password_salt', $jR['data'][0] ?? []), "http=$stR");
    [$stAU, $jAU] = $req('GET', $R['adminUser'], ['user_id' => $inviter->id], $adminAuth);
    t_check('P8g admin per-user view returns balance and ledger', $stAU === 200 && ($jAU['data']['balance'] ?? null) === 1500, "http=$stAU");
    [$stAdj, $jAdj] = $req('POST', $R['adjust'], ['user_id' => $inviter->id, 'amount' => 250], $adminAuth);
    t_check('P8h admin positive correction appends a row and raises the balance to 1750',
        $stAdj === 200 && ($jAdj['data']['balance'] ?? null) === 1750
        && DB::table('v2_points_ledger')->where('user_id', $inviter->id)->where('type', 'admin_adjust')->count() === 1, "http=$stAdj");
    [$stNeg] = $req('POST', $R['adjust'], ['user_id' => $inviter->id, 'amount' => -99999], $adminAuth);
    t_check('P8i a correction that would go below zero is refused with 422 and changes nothing',
        $stNeg === 422 && PointsService::balanceOf((int) $inviter->id) === 1750, "http=$stNeg");
    [$stZero] = $req('POST', $R['adjust'], ['user_id' => $inviter->id, 'amount' => 0], $adminAuth);
    t_check('P8j a zero correction is refused with 422', $stZero === 422, "http=$stZero");
    [$stRev] = $req('POST', $R['revoke'], ['id' => $rw->id], $adminAuth);
    t_check('P8k revoking a reward flips its status and offsets the points (1750 - 1500 = 250)',
        $stRev === 200 && DB::table('v2_referral_reward')->where('id', $rw->id)->value('status') === 'revoked'
        && PointsService::balanceOf((int) $inviter->id) === 250, "http=$stRev");
    [$stRev2] = $req('POST', $R['revoke'], ['id' => $rw->id], $adminAuth);
    t_check('P8l a second revoke is refused and does NOT offset twice',
        $stRev2 === 422 && PointsService::balanceOf((int) $inviter->id) === 250, "http=$stRev2");
    [$stAnon] = $req('GET', $R['rewards'], [], $auth);
    t_check('P8m a non-admin user cannot read the admin reward list', $stAnon !== 200, "http=$stAnon");

    // ---- P9 cash columns still untouched after everything above
    t_check('P9 no test user gained balance or commission_balance from points',
        DB::table('v2_user')->where('email', 'like', 'rollback-test-points-%')
            ->where(function ($q) { $q->where('balance', '!=', 0)->orWhere('commission_balance', '!=', 0); })->count() === 0);

    DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    t_check('unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

foreach ($before as $t => $sig) {
    t_check("rollback clean: $t unchanged", $rows($t) === $sig, "before=$sig after=" . $rows($t));
}
t_check('rollback clean: v2_user count unchanged', DB::table('v2_user')->count() === $usersBefore, "before=$usersBefore after=" . DB::table('v2_user')->count());
t_check('rollback clean: every user balance / commission_balance byte-identical', $cashSig() === $cashBefore);
t_check('rollback clean: no rollback-test-points user survived', DB::table('v2_user')->where('email', 'like', 'rollback-test-points-%')->count() === 0);

echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
