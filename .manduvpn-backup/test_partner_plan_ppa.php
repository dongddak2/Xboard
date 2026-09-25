<?php
// ManduVPN PP-A regression: plan audience / partner / VIP through the real HTTP kernel. Every row (plans,
// users, tokens, orders) lives in ONE transaction that is always rolled back; settings are never changed.
// Helper names are prefixed (never Laravel helper names). 500 is never a PASS; refusals must be 4xx.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Payment, Plan, User};
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function pp_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
function pp_req($http, $method, $uri, $auth, array $body = []) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
    $req = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
    $res = $http->handle($req);
    $exc = ($res->getStatusCode() >= 500 && !empty($res->exception)) ? ' EXC=' . class_basename($res->exception) . ': ' . substr($res->exception->getMessage(), 0, 140) : '';
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr($res->getContent(), 0, 160) . $exc];
}
function pp_user($admin = false, $partner = false) {
    $u = new User();
    $u->email = 'rollback-test-pp-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
    $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
    if ($admin) $u->is_admin = 1;
    $u->is_partner = $partner ? 1 : 0;
    $u->save();
    $u = User::find($u->id);
    return [$u, (new AuthService($u))->generateAuthData()['auth_data']];
}
$route = function ($action) {
    $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action));
    return $r ? '/' . $r->uri() : null;
};
$R = ['userPlans' => $route('V1\User\PlanController@fetch'), 'guestPlans' => $route('V1\Guest\PlanController@fetch'), 'save' => $route('V1\User\OrderController@save'),
      'checkout' => $route('V1\User\OrderController@checkout'), 'assign' => $route('Admin\OrderController@assign'), 'userUpdate' => $route('Admin\UserController@update'),
      'selfUpdate' => $route('V1\User\UserController@update'), 'planSave' => $route('Admin\PlanController@save')];
pp_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$toss = Payment::where('payment', 'TossPayments')->where('enable', 1)->whereRaw('UPPER(currency) = ?', ['KRW'])->orderBy('id')->first();
$groupId = (int) DB::table('v2_server_group')->orderBy('id')->value('id');
pp_check('setup: KRW Toss payment + a server group', $toss && $groupId, "toss=" . ($toss->id ?? 'none') . " group=$groupId");

Http::preventStrayRequests();
DB::beginTransaction();
try {
    $mkPlan = function ($name, $audience, $tier, $devices, $show = 1, $sell = 1) use ($groupId) {
        $p = new Plan();
        $p->forceFill(['name' => "rollback-test-pp $name", 'group_id' => $groupId, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => $devices,
            'show' => $show, 'sell' => $sell, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => $audience, 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $pub = ['basic' => $mkPlan('BASIC', 'public', 'basic', 2), 'pro' => $mkPlan('PRO', 'public', 'pro', 2), 'master' => $mkPlan('MASTER', 'public', 'master', 2)];
    $par = ['basic' => $mkPlan('BASIC PARTNER', 'partner', 'basic', 2), 'pro' => $mkPlan('PRO PARTNER', 'partner', 'pro', 2), 'master' => $mkPlan('MASTER PARTNER', 'partner', 'master', 2)];
    $vip = $mkPlan('VIP', 'admin_only', 'vip', 5, 0, 0);
    $vipShown = $mkPlan('VIP-SHOWN', 'admin_only', 'vip', 5, 1, 1);   // even show/sell=1 must never be listed or bought
    $ids = fn ($j) => array_map('intval', array_column($j['data'] ?? [], 'id'));
    $allIds = fn (array $ps) => array_map(fn ($p) => (int) $p->id, array_values($ps));

    [$normal, $normalAuth] = pp_user();
    [$partner, $partnerAuth] = pp_user(false, true);
    [$admin, $adminAuth] = pp_user(true);

    // ---- visibility ----
    [$st, $j] = pp_req($http, 'GET', $R['userPlans'], $normalAuth); $n = $ids($j);
    pp_check('PP1 normal user sees BASIC/PRO/MASTER', $st === 200 && !array_diff($allIds($pub), $n), "http=$st");
    pp_check('PP2 normal user does NOT see the partner plans', $st === 200 && !array_intersect($allIds($par), $n), "http=$st");
    [$st, $j] = pp_req($http, 'GET', $R['userPlans'], $partnerAuth); $p = $ids($j);
    pp_check('PP3 partner sees BASIC/PRO/MASTER PARTNER', $st === 200 && !array_diff($allIds($par), $p), "http=$st");
    pp_check('PP4 partner does NOT see the public plan of the same tier+devices (substituted)', $st === 200 && !array_intersect($allIds($pub), $p), "http=$st");
    [$st, $j] = pp_req($http, 'GET', $R['guestPlans'], null); $g = $ids($j);
    pp_check('PP5 guest sees public, never partner / admin_only', $st === 200 && !array_diff($allIds($pub), $g) && !array_intersect(array_merge($allIds($par), [$vip->id, $vipShown->id]), $g), "http=$st");
    pp_check('PP6 VIP never listed (normal / partner / guest), even with show=sell=1', !array_intersect([$vip->id, $vipShown->id], array_merge($n, $p, $g)));
    [$st] = pp_req($http, 'GET', $R['userPlans'], $normalAuth, ['id' => $par['pro']->id]);
    pp_check('PP6b normal user plan/fetch?id=<partner plan> refused (no single-plan leak)', $st >= 400 && $st < 500, "http=$st");

    // ---- purchase ----
    $save = fn ($auth, $plan, $extra = []) => pp_req($http, 'POST', $R['save'], $auth, ['plan_id' => $plan->id, 'period' => 'days_30'] + $extra);
    $orders = fn (User $u) => Order::where('user_id', $u->id)->count();
    foreach (['basic', 'pro', 'master'] as $t) {
        [$st, , $raw] = $save($normalAuth, $par[$t], ['payment_id' => $toss->id]);
        pp_check("PP7 normal -> $t PARTNER order/save refused 4xx, no order", $st >= 400 && $st < 500 && $orders($normal) === 0, "http=$st $raw");
    }
    [$st, $j, $raw] = $save($partnerAuth, $par['pro'], ['payment_id' => $toss->id]);
    $po = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
    pp_check('PP8 partner -> PRO PARTNER (KRW Toss) order/save OK, KRW price of the partner plan', $st === 200 && $po && strtoupper((string) $po->currency) === 'KRW' && (int) $po->total_amount === 2000, "http=$st $raw");
    if ($po) Order::where('id', $po->id)->delete();
    [$st, $j, $raw] = $save($partnerAuth, $par['basic']);
    $po = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
    pp_check('PP8b partner -> BASIC PARTNER (legacy CNY) order/save OK, CNY fen', $st === 200 && $po && (int) $po->total_amount === 1000, "http=$st $raw");
    if ($po) Order::where('id', $po->id)->delete();
    foreach ([[$normalAuth, $normal, 'normal'], [$partnerAuth, $partner, 'partner']] as [$auth, $u, $who]) {
        [$st, , $raw] = $save($auth, $vip, ['payment_id' => $toss->id]);
        [$st2, , $raw2] = $save($auth, $vipShown, ['payment_id' => $toss->id]);
        pp_check("PP9/10 $who -> VIP (hidden + shown) order/save refused 4xx, no order", $st >= 400 && $st < 500 && $st2 >= 400 && $st2 < 500 && $orders($u) === 0, "http=$st/$st2 $raw2");
    }

    // ---- admin ----
    $assign = fn ($u, $plan) => pp_req($http, 'POST', $R['assign'], $adminAuth, ['plan_id' => $plan->id, 'email' => $u->email, 'total_amount' => 1000, 'period' => 'days_30']);
    [$st, , $raw] = $assign($normal, $par['master']);
    pp_check('PP11 admin assign PARTNER plan to a normal user -> 422, no order', $st === 422 && $orders($normal) === 0, "http=$st $raw");
    [$st, $j, $raw] = $assign($partner, $par['master']);
    pp_check('PP12 admin assign PARTNER plan to a partner -> 200', $st === 200 && $orders($partner) === 1, "http=$st $raw");
    Order::where('user_id', $partner->id)->delete();
    [$st, , $raw] = $assign($normal, $vip);
    pp_check('PP13 admin assign VIP (admin_only) -> 200, is_partner NOT changed', $st === 200 && $orders($normal) === 1 && (int) User::find($normal->id)->is_partner === 0, "http=$st $raw");
    Order::where('user_id', $normal->id)->delete();
    $base = fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'is_admin' => 0, 'is_staff' => 0];
    [$s1] = pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($normal) + ['is_partner' => 1]); $v1 = (int) User::find($normal->id)->is_partner;
    [$s2] = pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($normal) + ['is_partner' => 0]); $v2 = (int) User::find($normal->id)->is_partner;
    [$s3] = pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($normal) + ['remarks' => 'rollback-test unrelated']); $v3 = (int) User::find($normal->id)->is_partner;
    pp_check('PP14 admin is_partner 0->1->0 round-trip; unrelated save keeps it', $s1 === 200 && $v1 === 1 && $s2 === 200 && $v2 === 0 && $s3 === 200 && $v3 === 0, "http=$s1/$s2/$s3 values=$v1/$v2/$v3");
    [$st] = pp_req($http, 'POST', $R['userUpdate'], $normalAuth, $base($normal) + ['is_partner' => 1]);
    [$st2] = pp_req($http, 'POST', $R['selfUpdate'], $normalAuth, ['remind_expire' => 1, 'is_partner' => 1]);
    pp_check('PP15 non-admin cannot set is_partner (admin API 403, own user/update ignores it)', $st === 403 && $st2 === 200 && (int) User::find($normal->id)->is_partner === 0, "http=$st/$st2");
    $pp = $par['basic'];
    $planBody = fn ($aud) => ['id' => $pp->id, 'name' => $pp->name, 'transfer_enable' => 100, 'group_id' => $groupId, 'device_limit' => 2, 'audience' => $aud];
    [$s1, , $r1] = pp_req($http, 'POST', $R['planSave'], $adminAuth, $planBody('public')); $a1 = Plan::find($pp->id)->audience;
    [$s2, , $r2] = pp_req($http, 'POST', $R['planSave'], $adminAuth, $planBody('partner')); $a2 = Plan::find($pp->id)->audience;
    [$s3] = pp_req($http, 'POST', $R['planSave'], $adminAuth, $planBody('vip')); $a3 = Plan::find($pp->id)->audience;
    pp_check('PP16 admin plan audience round-trip (public->partner), invalid value 422', $s1 === 200 && $a1 === 'public' && $s2 === 200 && $a2 === 'partner' && $s3 === 422 && $a3 === 'partner',
        "http=$s1/$s2/$s3 values=$a1/$a2/$a3 $r1");

    // ---- revocation ----
    [$rp, $rpAuth] = pp_user(false, true);
    $exp = time() + 10 * 86400;
    User::where('id', $rp->id)->update(['plan_id' => $par['pro']->id, 'group_id' => $groupId, 'expired_at' => $exp]);
    [$st] = pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($rp) + ['is_partner' => 0]);
    $after = User::find($rp->id);
    pp_check('PP17 revoke keeps the running subscription (plan_id, group_id, expired_at unchanged)', $st === 200 && (int) $after->is_partner === 0 && (int) $after->plan_id === (int) $par['pro']->id && (int) $after->group_id === $groupId && (int) $after->expired_at === $exp, "http=$st");
    [$st, , $raw] = $save($rpAuth, $par['pro']);
    pp_check('PP18 revoked partner cannot renew the partner plan -> 4xx, no order', $st >= 400 && $st < 500 && $orders($rp) === 0, "http=$st $raw");
    [$rp2, $rp2Auth] = pp_user(false, true);
    pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($rp2) + ['is_partner' => 0]);
    [$st, , $raw] = $save($rp2Auth, $pub['pro'], ['payment_id' => $toss->id]);
    pp_check('PP19 revoked partner can buy the public plan -> 200', $st === 200 && $orders($rp2) === 1, "http=$st $raw");
    [$st] = pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($rp) + ['is_partner' => 1]);
    [$st2, $j] = pp_req($http, 'GET', $R['userPlans'], $rpAuth);
    pp_check('PP19b re-granting partner restores partner plan visibility immediately', $st === 200 && $st2 === 200 && in_array((int) $par['pro']->id, $ids($j), true), "http=$st/$st2");

    // ---- pending order + revocation -> checkout refused before side effects ----
    [$pc, $pcAuth] = pp_user(false, true);
    [$st, $j, $raw] = $save($pcAuth, $par['master'], ['payment_id' => $toss->id]);
    $pend = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
    pp_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($pc) + ['is_partner' => 0]);
    [$st2, , $raw2] = $pend ? pp_req($http, 'POST', $R['checkout'], $pcAuth, ['trade_no' => $pend->trade_no, 'method' => $toss->id]) : [0, [], 'no order'];
    $pendAfter = $pend ? Order::find($pend->id) : null;
    pp_check('PP20 pending partner order + revoke -> checkout 4xx before side effects (still pending, no callback, no payment_id change)',
        $st === 200 && $pend && $st2 >= 400 && $st2 < 500 && (int) $pendAfter->status === 0 && $pendAfter->callback_no === null && (int) $pendAfter->payment_id === (int) $pend->payment_id,
        "save=$st checkout=$st2 $raw2");
} catch (\Throwable $e) {
    pp_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-pp-%')->count() + Plan::where('name', 'like', 'rollback-test-pp%')->count();
pp_check('rollback: no temp users / plans left', $left === 0, "left=$left");
pp_check('protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
