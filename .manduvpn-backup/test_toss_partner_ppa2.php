<?php
// ManduVPN PP-A2: partner eligibility before Toss confirm. ONE rolled-back transaction. The Toss confirm URL is
// faked (HTTP 400, nothing captured); any other outgoing request is blocked by preventStrayRequests and fails.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Payment, Plan, User};
use App\Services\{AuthService, PaymentService};
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Event, Http, Route};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
const TOSS_CONFIRM = 'https://api.tosspayments.com/v1/payments/confirm';
$fails = 0;
function a2_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
$GLOBALS['a2_urls'] = []; $GLOBALS['a2_confirm'] = 0; $GLOBALS['a2_stray'] = [];
Event::listen(RequestSending::class, function ($e) {
    $url = (string) $e->request->url(); $GLOBALS['a2_urls'][] = $url;
    if ($url !== TOSS_CONFIRM) $GLOBALS['a2_stray'][] = $url;
});
Http::preventStrayRequests();
Http::fake([TOSS_CONFIRM => function () { $GLOBALS['a2_confirm']++; return Http::response(['code' => 'ROLLBACK_TEST_FAKE', 'message' => 'faked by PP-A2 harness'], 400); }]);
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
function a2_user($partner) {
    $u = new User(); $u->email = 'rollback-test-a2-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32);
    $u->balance = 0; $u->is_partner = $partner ? 1 : 0; $u->save();
    return User::find($u->id);
}
// One notify. CONFIRM = the (faked) Toss confirm was requested; REJECTED = returned false with 0 requests and no exception.
function a2_notify(Payment $toss, Order $o) {
    $GLOBALS['a2_urls'] = []; $GLOBALS['a2_confirm'] = 0; $r = null; $exc = '';
    try { $r = (new PaymentService('TossPayments', $toss->id))->notify(['paymentKey' => 'tgen_rollbacktesta2000000000', 'orderId' => $o->trade_no, 'amount' => (string) $o->total_amount]); }
    catch (\Throwable $e) { $exc = class_basename($e) . ': ' . substr($e->getMessage(), 0, 120); }
    $n = count($GLOBALS['a2_urls']);
    $v = $GLOBALS['a2_confirm'] > 0 ? 'CONFIRM' : ($r ? 'ACCEPTED' : ($n === 0 && $exc === '' && $r === false ? 'REJECTED' : 'OTHER'));
    return [$v, $n, $GLOBALS['a2_confirm'], "http_attempts=$n confirm_hits={$GLOBALS['a2_confirm']}" . ($exc !== '' ? " exc=$exc" : '')];
}
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$toss = Payment::where('payment', 'TossPayments')->where('enable', 1)->whereRaw('UPPER(currency) = ?', ['KRW'])->orderBy('id')->first();
$groupId = (int) DB::table('v2_server_group')->orderBy('id')->value('id');
$notifyUri = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), 'Guest\PaymentController@notify'));
$saveUri = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), 'V1\User\OrderController@save'));
a2_check('setup: Toss KRW payment, server group, guest notify + order/save routes', $toss && $groupId && $notifyUri && $saveUri);

DB::beginTransaction();
try {
    $plan = function ($aud) use ($groupId) {
        // capacity_limit / speed_limit explicitly null like the passing PP-A fixture (DB default capacity_limit 0 = sold out)
        $p = new Plan(); $p->forceFill(['name' => "rollback-test-a2 $aud", 'group_id' => $groupId, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => $aud, 'service_tier' => 'pro'])->save();
        return Plan::find($p->id);
    };
    $pendingOrder = function (User $u, $planId) use ($toss) {
        $o = new Order(['user_id' => $u->id, 'plan_id' => $planId, 'type' => 1, 'period' => 'days_30', 'trade_no' => 'RBA2' . Str::random(20), 'total_amount' => 2000]);
        $o->payment_id = $toss->id; $o->currency = 'KRW'; $o->status = 0; $o->save();
        return Order::find($o->id);
    };
    $partnerPlan = $plan('partner'); $publicPlan = $plan('public'); $vipPlan = $plan('admin_only');
    $snapOf = fn (Order $o) => json_encode(Order::find($o->id)->only(['status', 'payment_id', 'callback_no', 'paid_at', 'total_amount', 'currency', 'updated_at']));

    // A2-T1: partner user buys the partner plan through the real order/save endpoint
    $u1 = a2_user(true);
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $res = $http->handle(Request::create('/' . $saveUri->uri(), 'POST', [], [], [], ['HTTP_AUTHORIZATION' => (new AuthService($u1))->generateAuthData()['auth_data'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['plan_id' => $partnerPlan->id, 'period' => 'days_30', 'payment_id' => $toss->id])));
    $tn = json_decode($res->getContent(), true)['data'] ?? null;
    $o1 = is_string($tn) ? Order::where('trade_no', $tn)->first() : null;
    a2_check('A2-T1 partner order/save -> HTTP 200, pending KRW partner order', $res->getStatusCode() === 200 && $o1 && (int) $o1->status === 0 && strtoupper((string) $o1->currency) === 'KRW' && (int) $o1->plan_id === (int) $partnerPlan->id,
        'http=' . $res->getStatusCode() . ' ' . ($o1 ? "status={$o1->status} currency={$o1->currency} total={$o1->total_amount}" : 'body=' . substr($res->getContent(), 0, 200)));
    if ($o1) {
        // A2-T2: partner status revoked -> refused by the PP-A2 guard before any Toss request
        User::where('id', $u1->id)->update(['is_partner' => 0]);
        $before = $snapOf($o1);
        [$v, $n, , $d] = a2_notify($toss, $o1);
        a2_check('A2-T2 revoked partner: notify REJECTED, 0 Toss requests, order unchanged', $v === 'REJECTED' && $n === 0 && $snapOf($o1) === $before, "$v $d");
        // A2-T2b: same through the real guest notify HTTP route
        $GLOBALS['a2_urls'] = [];
        $uri = '/' . str_replace(['{method}', '{uuid}'], ['TossPayments', $toss->uuid], $notifyUri->uri());
        $r2 = $http->handle(Request::create($uri, 'GET', ['paymentKey' => 'tgen_rollbacktesta2000000000', 'orderId' => $o1->trade_no, 'amount' => (string) $o1->total_amount], [], [], ['HTTP_ACCEPT' => 'application/json']));
        a2_check('A2-T2b guest notify route -> 4xx, 0 Toss requests, order unchanged', $r2->getStatusCode() >= 400 && $r2->getStatusCode() < 500 && count($GLOBALS['a2_urls']) === 0 && $snapOf($o1) === $before,
            'http=' . $r2->getStatusCode() . ' attempts=' . count($GLOBALS['a2_urls']));
        // A2-T3: partner status re-granted -> reaches the FAKED confirm exactly once; fake 400 -> nothing paid
        User::where('id', $u1->id)->update(['is_partner' => 1]);
        [$v, $n, $c, $d] = a2_notify($toss, $o1);
        a2_check('A2-T3 re-granted partner: exactly 1 (faked) Toss confirm request, order still pending', $v === 'CONFIRM' && $n === 1 && $c === 1 && (int) Order::find($o1->id)->status === 0, "$v $d");
    }
    // A2-T4..T6: controls keep the existing behaviour (reach the faked confirm)
    [$v, , , $d] = a2_notify($toss, $pendingOrder(a2_user(false), $publicPlan->id));
    a2_check('A2-T4 control: public pending KRW order reaches confirm', $v === 'CONFIRM', "$v $d");
    [$v, , , $d] = a2_notify($toss, $pendingOrder(a2_user(false), $vipPlan->id));
    a2_check('A2-T5 control: admin_only (VIP) pending order not rejected by PP-A2 (reaches confirm)', $v === 'CONFIRM', "$v $d");
    [$v, , , $d] = a2_notify($toss, $pendingOrder(a2_user(false), 99999999));
    a2_check('A2-T6 control: missing plan does not trigger the PP-A2 rejection (reaches confirm)', $v === 'CONFIRM', "$v $d");
    $ghost = $pendingOrder(a2_user(true), $partnerPlan->id); Order::where('id', $ghost->id)->update(['user_id' => 99999999]);
    [$v, $n, , $d] = a2_notify($toss, Order::find($ghost->id));
    a2_check('A2-T6b missing user + partner plan -> REJECTED before confirm (no exception)', $v === 'REJECTED' && $n === 0, "$v $d");
} catch (\Throwable $e) {
    a2_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
a2_check('no outgoing request other than the faked Toss confirm', $GLOBALS['a2_stray'] === [], json_encode($GLOBALS['a2_stray']));
$left = User::where('email', 'like', 'rollback-test-a2-%')->count() + Plan::where('name', 'like', 'rollback-test-a2%')->count() + Order::where('trade_no', 'like', 'RBA2%')->count();
a2_check('rollback: no temp users / plans / orders left', $left === 0, "left=$left");
a2_check('protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
