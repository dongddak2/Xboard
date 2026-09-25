<?php
// ManduVPN period tests through the real HTTP kernel (routes, Sanctum auth, FormRequest
// validation, controllers). Temp users/tokens/orders live in ONE transaction that is
// always rolled back. v2_plan and existing orders are only read.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Payment, Plan, User};
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
function testInfo($n, $d) { echo "INFO $n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create'); // ManduVPN coupon phase harness: remove(hook) without a callback is a no-op in Eventy 0.8
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
function post($http, $uri, $auth, array $body) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}   // no user cached across requests
    $req = Request::create($uri, 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => $auth, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], json_encode($body));
    $res = $http->handle($req);
    $exc = ($res->getStatusCode() >= 500 && !empty($res->exception)) ? ' EXC=' . class_basename($res->exception) . ': ' . substr($res->exception->getMessage(), 0, 140) : '';
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->getContent() . $exc];
}
function tmpUser($admin = false) {
    $u = new User();
    $u->email = 'rollback-test-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
    $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
    if ($admin) $u->is_admin = 1;
    $u->save();
    return User::find($u->id);
}
$isPremiumName = fn ($n) => (bool) preg_match('/premium|\x{D504}\x{B9AC}\x{BBF8}\x{C5C4}/iu', (string) $n); // "premium" or the Korean word for it (escaped: ASCII-only file)

// ---------------- D: read-only plan diagnosis ----------------
$groups = Schema::hasTable('v2_server_group') ? DB::table('v2_server_group')->pluck('name', 'id')->all() : [];
testInfo('D server groups', json_encode($groups, JSON_UNESCAPED_UNICODE));
$tier = [];
foreach (Plan::orderBy('id')->get() as $p) {
    $cny = optional($p->prices)['days_360'] ?? null; $krw = optional($p->prices_krw)['days_360'] ?? null;
    $g = $groups[$p->group_id] ?? null;
    $t = ($isPremiumName($p->name) || $isPremiumName($g)) ? 'PREMIUM' : 'BASIC';
    $tier[$p->id] = $t;
    testInfo("D plan {$p->id}", json_encode(['name' => $p->name, 'group_id' => $p->group_id, 'group' => $g, 'device_limit' => $p->device_limit,
        'show' => $p->show, 'sell' => $p->sell, 'prices.days_360' => $cny, 'prices_krw.days_360' => $krw,
        'old_test_regex_/premium/i_on_name' => (bool) preg_match('/premium/i', (string) $p->name), 'tier' => $t,
        'krw_is_cny_x200' => is_numeric($cny) && is_numeric($krw) ? ((int) $krw === (int) round($cny * 200)) : null], JSON_UNESCAPED_UNICODE));
}
foreach (['BASIC', 'PREMIUM'] as $t) {
    $devs = Plan::whereIn('id', array_keys(array_filter($tier, fn ($x) => $x === $t)))->where('sell', 1)->where('show', 1)->orderBy('device_limit')->get() // ManduVPN P0 assign harness
        ->filter(fn ($p) => collect(array_merge((array) $p->prices, (array) $p->prices_krw))->filter(fn ($v, $k) => str_starts_with((string) $k, 'days_') && is_numeric($v) && $v > 0)->isNotEmpty())
        ->pluck('device_limit')->values()->all();
    check("D structure: $t sold+shown+days_N-priced plans have exactly 1..5 devices (NULL fails)", array_map('intval', $devs) === [1, 2, 3, 4, 5], "device_limits=" . json_encode($devs));
}
$premCandidates = Plan::whereIn('id', array_keys(array_filter($tier, fn ($x) => $x === 'PREMIUM')))->orderBy('device_limit')->get();
foreach ($premCandidates as $p) {
    $why = [];
    if ((int) $p->sell !== 1) $why[] = 'sell!=1';
    if (!is_numeric(optional($p->prices_krw)['days_360'] ?? null) || optional($p->prices_krw)['days_360'] <= 0) $why[] = 'prices_krw.days_360 missing/<=0';
    if (!preg_match('/premium/i', (string) $p->name)) $why[] = 'name does not match old /premium/i';
    testInfo("D why old test skipped PREMIUM plan {$p->id}", $why ? implode(', ', $why) : 'would have matched');
}

// ---------------- E: existing E2E order (read-only) ----------------
$e2eBefore = optional(Order::where('trade_no', E2E_TN)->first())->getAttributes();
$e2eOwner = User::where('email', 'admin@test.manduvpn.kr')->value('id');
check('E existing E2E order: exists, 9000 KRW, owner admin@test.manduvpn.kr (completed Toss TEST order - status not asserted)',
    $e2eBefore && (int) $e2eBefore['total_amount'] === 9000
    && strtoupper((string) $e2eBefore['currency']) === 'KRW' && (int) $e2eBefore['user_id'] === (int) $e2eOwner,
    $e2eBefore ? "status={$e2eBefore['status']} total={$e2eBefore['total_amount']} currency={$e2eBefore['currency']} user_id={$e2eBefore['user_id']} owner_id=$e2eOwner" : 'not found');

// ---------------- T: HTTP-kernel tests ----------------
$toss = Payment::where('payment', 'TossPayments')->where('enable', 1)->whereRaw('UPPER(currency) = ?', ['KRW'])->orderBy('id')->first();
$sellable = fn ($p) => (int) $p->sell === 1;
$basic2 = Plan::orderBy('id')->get()->first(fn ($p) => $tier[$p->id] === 'BASIC' && (int) $p->device_limit === 2 && $sellable($p));
$premium = $premCandidates->first(fn ($p) => $sellable($p) && is_numeric(optional($p->prices_krw)['days_360'] ?? null) && optional($p->prices_krw)['days_360'] > 0);
check('T setup: KRW Toss payment', (bool) $toss, $toss ? "payment_id={$toss->id}" : '');
check('T setup: BASIC 2-device plan', (bool) $basic2, $basic2 ? "plan={$basic2->id} {$basic2->name}" : '');
check('T setup: PREMIUM plan (name or group) with sell=1 and prices_krw.days_360', (bool) $premium, $premium ? "plan={$premium->id} {$premium->name}" : 'see INFO D lines');
$assignRoute = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods()) && str_ends_with($r->getActionName(), 'Admin\OrderController@assign'));
testInfo('T admin assign route', $assignRoute ? '/' . $assignRoute->uri() : 'not found');

Http::preventStrayRequests();
DB::beginTransaction();
try {
    if ($toss && $basic2) {
        $u = tmpUser();
        $auth = (new AuthService($u))->generateAuthData()['auth_data'];
        $cases = [['BASIC2 days_30', $basic2, 'days_30'], ['BASIC2 days_90', $basic2, 'days_90']];
        if ($premium) $cases[] = ['PREMIUM days_360', $premium, 'days_360'];
        foreach ($cases as [$label, $plan, $period]) {
            Order::where('user_id', $u->id)->delete();
            $expect = (int) (optional($plan->prices_krw)[$period] ?? -1);
            // tampering on purpose: the server must ignore all four
            [$st, $j, $raw] = post($http, '/api/v1/user/order/save', $auth,
                ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id, 'currency' => 'CNY', 'total_amount' => 1, 'price' => 1, 'amount' => 1]);
            $tn = is_string($j['data'] ?? null) ? $j['data'] : null;
            $o = $tn ? Order::where('trade_no', $tn)->first() : null;
            check("T $label: HTTP 200 + order created", $st === 200 && $o, "http=$st " . ($o ? "trade_no=$tn" : substr($raw, 0, 160)));
            if ($o) {
                check("T $label: period", $o->period === $period, "order.period={$o->period}");
                check("T $label: payment_id = Toss", (int) $o->payment_id === (int) $toss->id, "order.payment_id={$o->payment_id} toss={$toss->id}");
                check("T $label: currency=KRW (client CNY ignored)", strtoupper((string) $o->currency) === 'KRW', "currency={$o->currency}");
                check("T $label: total_amount = prices_krw[$period] (client 1 ignored)", (int) $o->total_amount === $expect && $expect > 1, "total={$o->total_amount} prices_krw=$expect");
            }
        }
        Order::where('user_id', $u->id)->delete();
        [$st, $j, $raw] = post($http, '/api/v1/user/order/save', $auth, ['plan_id' => $basic2->id, 'period' => 'days_31', 'payment_id' => $toss->id]);
        check('T days_31 -> 422 (allow-list not loosened)', $st === 422 && !Order::where('user_id', $u->id)->exists(), "http=$st " . substr($raw, 0, 120));

        // admin order/assign (P2)
        if ($assignRoute) {
            $adm = tmpUser(true);
            $aauth = (new AuthService($adm))->generateAuthData()['auth_data'];
            $target = tmpUser();
            $a = fn (array $b) => post($http, '/' . $assignRoute->uri(), $aauth, $b);
            [$st, $j, $raw] = $a(['plan_id' => $basic2->id, 'email' => $target->email, 'total_amount' => 14000, 'period' => 'days_30']);
            $o = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
            check('A assign days_30 accepted', $st === 200 && $o && $o->period === 'days_30', "http=$st " . ($o ? "period={$o->period} currency={$o->currency} total={$o->total_amount}" : substr($raw, 0, 160)));
            if ($o) {
                $paidOk = (new \App\Services\OrderService($o))->paid('rollback-test-assign');
                $fo = Order::find($o->id); $tu = User::find($target->id);
                check('A-open assigned days_30 order -> paid()/open(): completed, user.plan_id set, expired_at = paid_at + 30d', $paidOk && (int) $fo->status === 3 && (int) $tu->plan_id === (int) $basic2->id && abs((int) $tu->expired_at - ((int) $fo->paid_at + 30 * 86400)) <= 3600,
                    'paid=' . var_export($paidOk, true) . " status={$fo->status} plan={$tu->plan_id} expired_at=" . date('Y-m-d H:i', (int) $tu->expired_at));
            }
            Order::where('user_id', $target->id)->delete();
            [$st, , $raw] = $a(['plan_id' => $basic2->id, 'email' => $target->email, 'total_amount' => 14000, 'period' => 'days_31']);
            check('A assign days_31 -> 422', $st === 422 && !Order::where('user_id', $target->id)->exists(), "http=$st " . substr($raw, 0, 100));
            [$st, , $raw] = $a(['plan_id' => $basic2->id, 'email' => $target->email, 'period' => 'days_30']);
            check('A assign without total_amount -> 422 (other rules intact)', $st === 422, "http=$st " . substr($raw, 0, 100));
            [$st, , $raw] = $a(['plan_id' => $basic2->id, 'email' => $target->email, 'total_amount' => 100, 'period' => 'month_price']);
            check('A assign legacy month_price -> 200', $st === 200, "http=$st " . substr($raw, 0, 100));
            [$st, , $raw] = post($http, '/' . $assignRoute->uri(), $auth, ['plan_id' => $basic2->id, 'email' => $target->email, 'total_amount' => 1, 'period' => 'days_30']);
            check('A assign by non-admin -> 403, no order', $st === 403 && !Order::where('user_id', $target->id)->where('total_amount', 1)->exists(), "http=$st");
        } else check('A admin assign route found', false);
    }
} catch (\Throwable $e) {
    check('T run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
check('R rollback: no temp users/orders left', User::where('email', 'like', 'rollback-test-%')->count() === 0);
check('R existing E2E order byte-identical before/after (attribute hash)', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === md5(json_encode($e2eBefore)));
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
