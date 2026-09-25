<?php
// ManduVPN coupon phase regression. POST /api/v1/user/order/save, coupon/check and the admin
// coupon/generate route go through the real HTTP kernel (routing, Sanctum, FormRequest).
// Every row (coupons, users, tokens, orders, temp CNY payment) lives in ONE transaction that is
// always rolled back. No Toss / notify call is possible (Http::preventStrayRequests()).
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Coupon, Order, Payment, Plan, User};
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

$fails = 0;
function check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
// Hook isolation (Eventy 0.8, verified in its source): HookManager::remove($hook) WITHOUT a callback
// removes nothing (null !== listener), which is what leaked the R5d throwing hook into later tests.
// removeAllActions($hook) is the real "clear this hook" call; it only affects this test process.
function orderHooks(): \Illuminate\Support\Collection {
    return app('eventy')->getAction()->getListeners()->where('hook', 'order.after_create');
}
app('eventy')->removeAllActions('order.after_create');
check('setup: order.after_create has no listeners in the test process', orderHooks()->count() === 0, 'listeners=' . orderHooks()->count());
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
// 4th element: the exception behind a 5xx (the handler keeps it on the response), so a 500 is never mistaken for a rule refusal.
function post($http, $uri, $auth, array $body) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $req = Request::create($uri, 'POST', [], [], [], ['HTTP_AUTHORIZATION' => $auth, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($body));
    $res = $http->handle($req);
    $exc = ($res->getStatusCode() >= 500 && !empty($res->exception)) ? class_basename($res->exception) . ': ' . substr($res->exception->getMessage(), 0, 140) : '';
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr($res->getContent(), 0, 160) . ($exc ? " EXC=$exc" : ''), $exc];
}
function tmpUser($admin = false) {
    $u = new User();
    $u->email = 'rollback-test-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
    $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
    if ($admin) $u->is_admin = 1;
    $u->save();
    $u = User::find($u->id);
    return [$u, (new AuthService($u))->generateAuthData()['auth_data']];
}
function mk(array $a) {
    return Coupon::create(array_merge(['name' => 'rollback-test', 'code' => 'RBC' . Str::upper(Str::random(8)), 'type' => 2, 'value' => 10, 'show' => 1,
        'started_at' => time() - 60, 'ended_at' => time() + 3600], $a));
}
$toss = Payment::where('payment', 'TossPayments')->where('enable', 1)->whereRaw('UPPER(currency) = ?', ['KRW'])->orderBy('id')->first();
$plan = null;
foreach (Plan::where('sell', 1)->orderBy('id')->get() as $p) {
    if (is_numeric(optional($p->prices)['days_30'] ?? null) && is_numeric(optional($p->prices_krw)['days_30'] ?? null)) { $plan = $p; break; }
}
$other = $plan ? Plan::where('id', '<>', $plan->id)->value('id') : null;
check('setup: KRW Toss payment + plan with CNY/KRW days_30', $toss && $plan, $plan ? "plan={$plan->id} toss={$toss->id}" : '');
if (!$toss || !$plan) { echo "RESULT FAIL\n"; exit(1); }
$cnyAmt = (int) round($plan->prices['days_30'] * 100);
$krwAmt = (int) $plan->prices_krw['days_30'];
echo "INFO amounts: CNY days_30 = $cnyAmt fen, KRW days_30 = $krwAmt won\n";
$order = function ($auth, array $body) use ($http) {
    [$st, $j, $raw, $exc] = post($http, '/api/v1/user/order/save', $auth, array_merge(['period' => 'days_30'], $body));
    $o = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
    return [$st, $o, ($j['message'] ?? $raw) . ($exc ? " EXC=$exc" : '')];
};
$ok = function ($name, $r, $cur, $total, $disc, $payId) {
    [$st, $o, $m] = $r;
    check($name, $st === 200 && $o && strtoupper((string) $o->currency) === $cur && (int) $o->total_amount === $total && (int) $o->discount_amount === $disc && (int) $o->payment_id === (int) $payId,
        $o ? "http=$st currency={$o->currency} total={$o->total_amount} (want $total) discount={$o->discount_amount} (want $disc) payment_id=" . var_export($o->payment_id, true) : "http=$st $m");
};
// A refusal only counts when it is a business-rule rejection (4xx, ApiException default 400) and no
// order exists. A 5xx is an error, never a PASS.
$no = function ($name, $r) { [$st, $o, $m] = $r; check($name, $st >= 400 && $st < 500 && !$o, "http=$st $m"); };

Http::preventStrayRequests();
DB::beginTransaction();
try {
    $cny = Payment::create(['uuid' => Str::lower(Str::random(32)), 'payment' => 'AlipayF2F', 'name' => 'rollback-test CNY', 'config' => [], 'enable' => 1, 'currency' => 'CNY']);
    $P = ['plan_id' => $plan->id];
    $u = fn () => tmpUser()[1];

    // ---- CNY ----
    $legacy = mk(['type' => 1, 'value' => 500]); // legacy row: fixed, currency NULL (= CNY fen)
    $ok('C1 legacy fixed (currency NULL) on CNY order = 500 fen off', $order($u(), $P + ['payment_id' => $cny->id, 'coupon_code' => $legacy->code]), 'CNY', $cnyAmt - 500, 500, $cny->id);
    $ok('C1b legacy fixed on legacy order without payment_id', $order($u(), $P + ['coupon_code' => $legacy->code]), 'CNY', $cnyAmt - 500, 500, null);
    $cnyFixed = mk(['type' => 1, 'value' => 300, 'currency' => 'CNY']);
    $ok('C2 CNY fixed on CNY order', $order($u(), $P + ['payment_id' => $cny->id, 'coupon_code' => $cnyFixed->code]), 'CNY', $cnyAmt - 300, 300, $cny->id);
    $no('C3 CNY fixed on KRW Toss order refused', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $cnyFixed->code]));
    $no('C3b legacy fixed (NULL=CNY) on KRW Toss order refused', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $legacy->code]));
    $pct = mk(['type' => 2, 'value' => 15]);
    $ok('C4 15% on CNY order (floored fen)', $order($u(), $P + ['payment_id' => $cny->id, 'coupon_code' => $pct->code]), 'CNY', $cnyAmt - intdiv($cnyAmt * 15, 100), intdiv($cnyAmt * 15, 100), $cny->id);

    // ---- KRW ----
    $krwFixed = mk(['type' => 1, 'value' => 1000, 'currency' => 'KRW']);
    $ok('K1 KRW fixed 1000 on Toss KRW order', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $krwFixed->code]), 'KRW', $krwAmt - 1000, 1000, $toss->id);
    $no('K2 KRW fixed on CNY order refused', $order($u(), $P + ['payment_id' => $cny->id, 'coupon_code' => $krwFixed->code]));
    $ok('K3 15% on Toss KRW order (floored won)', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $pct->code]), 'KRW', $krwAmt - intdiv($krwAmt * 15, 100), intdiv($krwAmt * 15, 100), $toss->id);
    [$st, $o] = $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $krwFixed->code]);
    check('K4 Toss KRW + coupon: total_amount is integer won = prices_krw - discount, payment row is TossPayments',
        $o && (string) (int) $o->total_amount === (string) $o->total_amount && (int) $o->total_amount === $krwAmt - 1000 && Payment::find($o->payment_id)->payment === 'TossPayments',
        $o ? 'total=' . var_export($o->total_amount, true) : "http=$st");

    // ---- common rules ----
    $no('R1 expired coupon', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['ended_at' => time() - 10, 'started_at' => time() - 100])->code]));
    $no('R2 inactive (show=0)', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['show' => 0])->code]));
    $no('R3 wrong plan', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['limit_plan_ids' => [(string) ($other ?: 999999)]])->code]));
    $no('R4 wrong period', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['limit_period' => ['days_60']])->code]));
    $lim = mk(['limit_use' => 1]);
    $r = $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $lim->code]);
    check('R5a limit_use=1 first order passes', $r[0] === 200 && $r[1], "http={$r[0]} {$r[2]}");
    $no('R5b limit_use=1 second order refused', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $lim->code]));
    check('R5c limit_use decremented exactly once', (int) Coupon::find($lim->id)->limit_use === 0, 'limit_use=' . Coupon::find($lim->id)->limit_use);
    $lim2 = mk(['limit_use' => 1]);
    // The throwing hook is kept in a variable so it can be unregistered by the SAME instance:
    // Eventy 0.8 Event::remove() matches a Closure listener via HashedCallable::is(); a null callback matches nothing.
    $forceFail = function () { throw new \RuntimeException('forced failure after coupon'); };
    HookManager::register('order.after_create', $forceFail);
    check('R5d-setup forced-failure hook registered', orderHooks()->count() === 1, 'listeners=' . orderHooks()->count());
    try {
        $r = $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $lim2->code]);
    } finally {
        HookManager::remove('order.after_create', $forceFail);
    }
    $stillThere = orderHooks()->filter(fn ($l) => ($l['callback'] instanceof \TorMorten\Eventy\HashedCallable ? $l['callback']->getCallback() : $l['callback']) === $forceFail)->count();
    check('R5d-isolation forced-failure hook unregistered, hook empty again', $stillThere === 0 && orderHooks()->count() === 0, "matching=$stillThere listeners=" . orderHooks()->count());
    if ($stillThere !== 0 || orderHooks()->count() !== 0) {
        throw new \RuntimeException('ISOLATION FAILED: order.after_create still has listeners after R5d - remaining tests aborted');
    }
    check('R5d order failing after coupon (HTTP 500 from the forced hook) -> no order and limit_use NOT consumed',
        $r[0] === 500 && str_contains($r[2], 'forced failure after coupon') && !$r[1] && (int) Coupon::find($lim2->id)->limit_use === 1,
        "http={$r[0]} limit_use=" . Coupon::find($lim2->id)->limit_use . ' ' . substr($r[2], -90));
    [$st, $o] = $order($u(), $P + ['payment_id' => $toss->id]);
    check('R5e first order after R5d succeeds (no hook leakage)', $st === 200 && $o, "http=$st");
    $per = mk(['limit_use_with_user' => 1]);
    [$pu, $pa] = tmpUser();
    $r = $order($pa, $P + ['payment_id' => $toss->id, 'coupon_code' => $per->code]);
    if ($r[1]) Order::where('id', $r[1]->id)->update(['status' => Order::STATUS_COMPLETED]);
    $no('R6 per-user limit 1: same user second use refused', $order($pa, $P + ['payment_id' => $toss->id, 'coupon_code' => $per->code]));

    // ---- R12-A sequential correctness: the coupon RULES (not DB locking) enforce the limits ----
    $sa = mk(['limit_use_with_user' => 1]);
    [$su, $sAuth] = tmpUser();
    [$st1, $o1] = $order($sAuth, $P + ['payment_id' => $toss->id, 'coupon_code' => $sa->code]);
    $pendingRefusal = '';
    try {
        $svc = new \App\Services\CouponService($sa->code);
        $svc->setUserId($su->id); $svc->setPlanId($plan->id); $svc->setPeriod('days_30');
        $svc->check();
    } catch (\App\Exceptions\ApiException $e) { $pendingRefusal = $e->getMessage(); }
    check('R12-A2 per-user limit counts the PENDING order (CouponService refuses a 2nd use while order 1 is unpaid)', $o1 && (int) $o1->status === 0 && $pendingRefusal !== '', 'refusal=' . $pendingRefusal);
    if ($o1) Order::where('id', $o1->id)->update(['status' => Order::STATUS_COMPLETED]);
    [$st2, $o2, $m2] = $order($sAuth, $P + ['payment_id' => $toss->id, 'coupon_code' => $sa->code]);
    $cnt = Order::where('user_id', $su->id)->where('coupon_id', $sa->id)->count();
    check('R12-A1 sequential limit_use_with_user=1: 1st order OK, 2nd refused by the coupon rule (HTTP 4xx), order count 1',
        $st1 === 200 && $o1 && $st2 >= 400 && $st2 < 500 && !$o2 && $cnt === 1, "http1=$st1 http2=$st2 count=$cnt msg=$m2");
    $sl = mk(['limit_use' => 1]);
    [$stA, $oA] = $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $sl->code]);
    [$stB, $oB, $mB] = $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $sl->code]);
    check('R12-A3 sequential limit_use=1 across 2 users: 1st OK, 2nd refused (4xx), limit_use 0, 1 order',
        $stA === 200 && $oA && $stB >= 400 && $stB < 500 && !$oB && (int) Coupon::find($sl->id)->limit_use === 0 && Order::where('coupon_id', $sl->id)->count() === 1,
        "httpA=$stA httpB=$stB limit_use=" . Coupon::find($sl->id)->limit_use . " msg=$mB");
    $nu = mk(['new_user_only' => 1]);
    [$ou, $oa] = tmpUser();
    $r = $order($oa, $P + ['payment_id' => $toss->id]);
    if ($r[1]) Order::where('id', $r[1]->id)->update(['status' => Order::STATUS_COMPLETED]);
    $no('R7 new_user_only + user with a completed paid order refused', $order($oa, $P + ['payment_id' => $toss->id, 'coupon_code' => $nu->code]));
    $ok('R8 new_user_only + brand-new user passes', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $nu->code]), 'KRW', $krwAmt - intdiv($krwAmt * 10, 100), intdiv($krwAmt * 10, 100), $toss->id);
    [$cu, $ca] = tmpUser();
    $r = $order($ca, $P + ['payment_id' => $toss->id]);
    if ($r[1]) Order::where('id', $r[1]->id)->update(['status' => Order::STATUS_CANCELLED]);
    $ok('R8b new_user_only + user whose only order was cancelled passes', $order($ca, $P + ['payment_id' => $toss->id, 'coupon_code' => $nu->code]), 'KRW', $krwAmt - intdiv($krwAmt * 10, 100), intdiv($krwAmt * 10, 100), $toss->id);
    $no('R9 min_amount (KRW) above order amount refused', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['type' => 1, 'value' => 100, 'currency' => 'KRW', 'min_amount' => $krwAmt + 1])->code]));
    $ok('R9b min_amount (KRW) equal to order amount passes', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['type' => 1, 'value' => 100, 'currency' => 'KRW', 'min_amount' => $krwAmt])->code]), 'KRW', $krwAmt - 100, 100, $toss->id);
    $no('R10 fixed KRW >= order amount refused (no zero/negative order)', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['type' => 1, 'value' => $krwAmt, 'currency' => 'KRW'])->code]));
    $no('R10b 100% coupon refused (no zero order)', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => mk(['type' => 2, 'value' => 100])->code]));
    $ok('R11 client currency/total_amount/discount_amount/amount/price ignored', $order($u(), $P + ['payment_id' => $toss->id, 'coupon_code' => $pct->code,
        'currency' => 'CNY', 'total_amount' => 1, 'discount_amount' => 999999, 'amount' => 1, 'price' => 1]), 'KRW', $krwAmt - intdiv($krwAmt * 15, 100), intdiv($krwAmt * 15, 100), $toss->id);

    // ---- coupon/check preview ----
    [$st, $j] = post($http, '/api/v1/user/coupon/check', $u(), ['code' => $pct->code, 'plan_id' => $plan->id, 'period' => 'days_30', 'payment_id' => $toss->id]);
    $pv = $j['data']['preview'] ?? null;
    check('P1 coupon/check preview (KRW 15%) computed server-side', $st === 200 && $pv && $pv['currency'] === 'KRW' && (int) $pv['total_amount'] === $krwAmt - intdiv($krwAmt * 15, 100), "http=$st " . json_encode($pv));
    [$st] = post($http, '/api/v1/user/coupon/check', $u(), ['code' => $cnyFixed->code, 'plan_id' => $plan->id, 'period' => 'days_30', 'payment_id' => $toss->id]);
    check('P2 coupon/check refuses CNY fixed coupon for KRW payment', $st !== 200, "http=$st");

    // ---- admin coupon/generate validation ----
    $gen = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods()) && str_ends_with($r->getActionName(), 'Admin\CouponController@generate'));
    [$au, $aa] = tmpUser(true);
    $base = ['name' => 'rollback-test admin', 'started_at' => time(), 'ended_at' => time() + 3600];
    $g = fn (array $b) => post($http, '/' . $gen->uri(), $aa, $base + $b);
    [$st] = $g(['type' => 1, 'value' => 500, 'code' => 'RBCA' . Str::upper(Str::random(6))]);
    check('A1 fixed coupon without currency -> 422', $st === 422, "http=$st");
    $codeK = 'RBCA' . Str::upper(Str::random(6));
    [$st] = $g(['type' => 1, 'value' => 5000, 'currency' => 'krw', 'code' => $codeK, 'new_user_only' => true, 'min_amount' => 10000]);
    $row = Coupon::where('code', $codeK)->first();
    check('A2 fixed KRW coupon saved with currency=KRW, new_user_only, min_amount', $st === 200 && $row && $row->currency === 'KRW' && $row->new_user_only === true && (int) $row->min_amount === 10000, "http=$st " . json_encode($row ? $row->only(['currency', 'new_user_only', 'min_amount']) : null));
    [$st] = $g(['type' => 2, 'value' => 10, 'currency' => 'CNY', 'code' => 'RBCA' . Str::upper(Str::random(6))]);
    check('A3 percentage with currency -> 422', $st === 422, "http=$st");
    [$st] = $g(['type' => 2, 'value' => 10, 'min_amount' => 100, 'code' => 'RBCA' . Str::upper(Str::random(6))]);
    check('A4 percentage with min_amount -> 422', $st === 422, "http=$st");
    [$st] = $g(['type' => 2, 'value' => 150, 'code' => 'RBCA' . Str::upper(Str::random(6))]);
    check('A5 percentage > 100 -> 422', $st === 422, "http=$st");
    [$st] = $g(['id' => $legacy->id, 'type' => 1, 'value' => 500, 'code' => $legacy->code]);
    check('A6 editing a legacy fixed coupon requires an explicit currency -> 422', $st === 422, "http=$st");
    $codeP = 'RBCA' . Str::upper(Str::random(6));
    [$st] = $g(['type' => 2, 'value' => 10, 'code' => $codeP, 'limit_period' => ['days_30', 'days_360'], 'limit_plan_ids' => [(string) $plan->id]]);
    $row = Coupon::where('code', $codeP)->first();
    check('A7 percentage coupon with days_N periods saved, currency NULL', $st === 200 && $row && $row->currency === null && $row->limit_period === ['days_30', 'days_360'], "http=$st");

    // ---- preview == order/save, preview has no side effects ----
    $pc = mk(['type' => 1, 'value' => 700, 'currency' => 'KRW', 'limit_use' => 3]);
    $pAuth = $u();
    $ordersBefore = Order::count();
    [$st, $j] = post($http, '/api/v1/user/coupon/check', $pAuth, ['code' => $pc->code, 'plan_id' => $plan->id, 'period' => 'days_30', 'payment_id' => $toss->id]);
    $pv = $j['data']['preview'] ?? null;
    check('P2b coupon/check changes nothing (limit_use, order count)', (int) Coupon::find($pc->id)->limit_use === 3 && Order::count() === $ordersBefore, 'limit_use=' . Coupon::find($pc->id)->limit_use);
    [, $po] = $order($pAuth, $P + ['payment_id' => $toss->id, 'coupon_code' => $pc->code]);
    check('P1b preview discount/total == order/save discount/total', $pv && $po && (int) $pv['total_amount'] === (int) $po->total_amount && (int) $pv['discount_amount'] === (int) $po->discount_amount,
        json_encode($pv) . ' order=' . ($po ? "{$po->discount_amount}/{$po->total_amount}" : 'none'));

    // ---- new user: pending-only user still counts as new ----
    [$pu2, $pa2] = tmpUser();
    $order($pa2, $P + ['payment_id' => $toss->id]);
    check('R8c user with only a pending order is still a new user', Order::where('user_id', $pu2->id)->where('status', 0)->exists() && !\App\Services\CouponService::hasPaidOrder($pu2->id));

    // ---- lifecycle: unpaid cancel releases the use exactly once ----
    $lc = mk(['limit_use' => 5]);
    [$lu, $la] = tmpUser();
    [, $lo] = $order($la, $P + ['payment_id' => $toss->id, 'coupon_code' => $lc->code]);
    check('L1 coupon order created -> limit_use 5 -> 4', $lo && (int) Coupon::find($lc->id)->limit_use === 4, 'limit_use=' . Coupon::find($lc->id)->limit_use);
    $stale = $lo ? Order::find($lo->id) : null;
    [$st] = $lo ? post($http, '/api/v1/user/order/cancel', $la, ['trade_no' => $lo->trade_no]) : [0];
    check('L2 unpaid order cancelled via user API -> limit_use restored once (4 -> 5)', $lo && $st === 200 && (int) Order::find($lo->id)->status === 2 && (int) Coupon::find($lc->id)->limit_use === 5, "http=$st limit_use=" . Coupon::find($lc->id)->limit_use);
    $r1 = $stale ? (new \App\Services\OrderService($stale))->cancel() : null;
    $r2 = $lo ? (new \App\Services\OrderService(Order::find($lo->id)))->cancel() : null;
    check('L3 re-cancel (stale pending copy + fresh copy) releases nothing', $r1 === false && $r2 === false && (int) Coupon::find($lc->id)->limit_use === 5, 'r1=' . var_export($r1, true) . ' r2=' . var_export($r2, true) . ' limit_use=' . Coupon::find($lc->id)->limit_use);
    [$lu4, $la4] = tmpUser();
    [, $lo4] = $order($la4, $P + ['payment_id' => $toss->id, 'coupon_code' => $lc->code]);
    if ($lo4) Order::where('id', $lo4->id)->update(['status' => Order::STATUS_COMPLETED]);
    $r4 = $lo4 ? (new \App\Services\OrderService(Order::find($lo4->id)))->cancel() : null;
    check('L4 completed coupon order: cancel refused, use not restored', $lo4 && $r4 === false && (int) Order::find($lo4->id)->status === 3 && (int) Coupon::find($lc->id)->limit_use === 4, 'limit_use=' . Coupon::find($lc->id)->limit_use);
    [$lu5, $la5] = tmpUser();
    [, $lo5] = $order($la5, $P + ['payment_id' => $toss->id]);
    [$st] = $lo5 ? post($http, '/api/v1/user/order/cancel', $la5, ['trade_no' => $lo5->trade_no]) : [0];
    check('L5 order without coupon: cancel OK, coupon counters untouched', $lo5 && $st === 200 && (int) Order::find($lo5->id)->status === 2 && (int) Coupon::find($lc->id)->limit_use === 4, "http=$st");
    [$lu6, $la6] = tmpUser();
    $lu6->balance = 1; $lu6->save();
    [, $lo6] = $order($la6, $P + ['payment_id' => $cny->id]);
    if ($lo6) { post($http, '/api/v1/user/order/cancel', $la6, ['trade_no' => $lo6->trade_no]); (new \App\Services\OrderService(Order::find($lo6->id)))->cancel(); }
    check('L6 legacy CNY balance refunded exactly once across two cancels', $lo6 && (int) $lo6->balance_amount === 1 && (int) User::find($lu6->id)->balance === 1, $lo6 ? "balance_amount={$lo6->balance_amount} user_balance=" . User::find($lu6->id)->balance : 'no order');
    $un = mk([]);
    [$lu7, $la7] = tmpUser();
    [, $lo7] = $order($la7, $P + ['payment_id' => $toss->id, 'coupon_code' => $un->code]);
    if ($lo7) post($http, '/api/v1/user/order/cancel', $la7, ['trade_no' => $lo7->trade_no]);
    check('L7 unlimited coupon (limit_use NULL) stays NULL after cancel', $lo7 && Coupon::find($un->id)->limit_use === null);
    $lc8 = mk(['limit_use' => 2]);
    [$lu8, $la8] = tmpUser();
    [, $lo8] = $order($la8, $P + ['payment_id' => $toss->id, 'coupon_code' => $lc8->code]);
    if ($lo8) {
        Order::where('id', $lo8->id)->update(['created_at' => time() - 3 * 3600]);
        (new \App\Jobs\OrderHandleJob($lo8->trade_no))->handle();
        (new \App\Jobs\OrderHandleJob($lo8->trade_no))->handle();
    }
    check('L8 unpaid order expired by OrderHandleJob (run twice) -> released once (1 -> 2)', $lo8 && (int) Order::find($lo8->id)->status === 2 && (int) Coupon::find($lc8->id)->limit_use === 2, 'limit_use=' . Coupon::find($lc8->id)->limit_use);

    // ---- admin API round trip + CSV ----
    $route = function ($action) {
        $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), 'Admin\CouponController@' . $action));
        return $r ? '/' . $r->uri() : null;
    };
    $codeT = 'RBCTEST' . Str::upper(Str::random(5));
    [$st] = post($http, $route('generate'), $aa, $base + ['name' => 'rollback-test TEST api', 'type' => 1, 'value' => 5000, 'currency' => 'KRW', 'new_user_only' => true, 'min_amount' => 20000, 'code' => $codeT]);
    check('API generate (create) KRW fixed', $st === 200, "http=$st");
    [$st, $j] = post($http, $route('fetch'), $aa, ['current' => 1, 'pageSize' => 50, 'filter' => [['id' => 'code', 'value' => $codeT]]]);
    $row = collect($j['data'] ?? [])->firstWhere('code', $codeT);
    check('API fetch returns currency/new_user_only/min_amount', $st === 200 && $row && $row['currency'] === 'KRW' && $row['new_user_only'] == true && (int) $row['min_amount'] === 20000,
        json_encode($row ? array_intersect_key($row, array_flip(['currency', 'new_user_only', 'min_amount', 'show'])) : null));
    $cid = (int) ($row['id'] ?? 0);
    $showBefore = $cid ? (bool) Coupon::find($cid)->show : false;
    [$st] = post($http, $route('show'), $aa, ['id' => $cid]);
    check('API show toggles', $st === 200 && $cid && (bool) Coupon::find($cid)->show === !$showBefore, "http=$st");
    [$st] = post($http, $route('update'), $aa, ['id' => $cid, 'show' => 1]);
    check('API update (show=1)', $st === 200 && $cid && (bool) Coupon::find($cid)->show === true, "http=$st");
    [$st] = post($http, $route('generate'), $aa, $base + ['id' => $cid, 'name' => 'rollback-test TEST api', 'type' => 1, 'value' => 6000, 'currency' => 'KRW', 'new_user_only' => false, 'min_amount' => null, 'code' => $codeT]);
    $c = $cid ? Coupon::find($cid) : null;
    check('API generate (edit) round-trips value/currency/new_user_only/min_amount', $st === 200 && $c && (int) $c->value === 6000 && $c->currency === 'KRW' && $c->new_user_only === false && $c->min_amount === null, "http=$st");
    [$st] = post($http, $route('drop'), $aa, ['id' => $cid]);
    check('API drop', $st === 200 && $cid && !Coupon::find($cid), "http=$st");
    $csv = function (array $b) use ($http, $route, $aa, $base) {
        ob_start();
        [$st] = post($http, $route('generate'), $aa, $base + $b + ['generate_count' => 1]);
        $out = (string) ob_get_clean();
        $lines = array_values(array_filter(explode("\r\n", $out)));
        return [$st, $lines[0] ?? '', explode(',', $lines[1] ?? '')];
    };
    [$st, $hdr, $col] = $csv(['name' => 'rollback-test csv', 'type' => 1, 'value' => 3000, 'currency' => 'CNY']);
    check('CSV header has trailing currency column', substr_count($hdr, ',') === 9 && str_ends_with($hdr, ',currency'), $hdr);
    check('CSV fixed CNY 3000 fen -> 30 (yuan), currency CNY', ($col[2] ?? '') === '30' && ($col[9] ?? '') === 'CNY', implode(',', $col));
    [, , $col] = $csv(['name' => 'rollback-test csv', 'type' => 1, 'value' => 5000, 'currency' => 'KRW']);
    check('CSV fixed KRW 5000 -> 5000 (won), currency KRW', ($col[2] ?? '') === '5000' && ($col[9] ?? '') === 'KRW', implode(',', $col));
    [, , $col] = $csv(['name' => 'rollback-test csv', 'type' => 2, 'value' => 15]);
    check('CSV percentage 15 -> 15%, no currency', ($col[2] ?? '') === '15%' && ($col[9] ?? 'x') === '', implode(',', $col));
} catch (\Throwable $e) {
    check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = Coupon::where('code', 'like', 'RBC%')->orWhere('name', 'like', 'rollback-test%')->count() + User::where('email', 'like', 'rollback-test-%')->count() + Payment::where('name', 'like', 'rollback-test%')->count();
check('rollback: no temp coupon/user/payment rows left', $left === 0, "left=$left");
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
