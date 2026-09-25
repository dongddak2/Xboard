<?php
// ManduVPN KRW/CNY regression test v4. Two separate purposes:
//  [CNY legacy regression]  adding KRW/Toss must not break existing CNY price/order/
//                           coupon/balance/commission behaviour (NOT "pay CNY via Toss").
//  [Toss KRW-only]          TossPayments accepts only pending KRW orders of a KRW row, and
//                           every guard runs BEFORE the confirm HTTP request.
// Every row it needs lives inside ONE DB transaction that is always rolled back.
// Http::preventStrayRequests() blocks every outgoing call; a RequestSending listener
// records each ATTEMPT so "reached the Toss confirm call" is detectable.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\User\OrderSave;
use App\Models\{Coupon, Order, Payment, Plan, User};
use App\Services\PaymentService;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{DB, Event, Http};
use Illuminate\Support\Str;

const CANCELLED = '2026092308094362768198814';
$fails = 0;
$GLOBALS['httpAttempts'] = []; $GLOBALS['logs'] = [];
function check($name, $ok, $detail = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " -- $detail" : '') . PHP_EOL; }
function cur($o) { return strtoupper((string) ($o->currency ?? '')); }
function redact($s) { return preg_replace('/\b(test|live)_[a-z]{1,4}_[A-Za-z0-9_]+/', '<REDACTED>', (string) $s); }
function saveOrder(User $u, array $in) {
    $req = OrderSave::create('/api/v1/user/order/save', 'POST', $in);
    $req->setUserResolver(fn () => $u);
    try { $res = app(OrderController::class)->save($req); }
    catch (\Throwable $e) { return [null, class_basename($e) . ': ' . $e->getMessage()]; }
    $tradeNo = json_decode($res->getContent(), true)['data'] ?? null;
    return [$tradeNo ? Order::where('trade_no', $tradeNo)->first() : null, $tradeNo ? '' : $res->getContent()];
}
function checkoutAs(User $u, Order $o, int $method) {
    $req = Request::create('/api/v1/user/order/checkout', 'POST', ['trade_no' => $o->trade_no, 'method' => $method]);
    $req->setUserResolver(fn () => $u);
    try { return (string) app(OrderController::class)->checkout($req)->getStatusCode(); }
    catch (\Throwable $e) { return class_basename($e) . ': ' . substr($e->getMessage(), 0, 120); }
}
// CONFIRM_CALLED = an outgoing HTTP request was attempted (and blocked);
// REJECTED = refused with no request and without reaching the secret check;
// SECRET_STAGE = no request, but it got as far as the secret check (a guard is missing);
// ACCEPTED = success WITHOUT any request (a bug).
function tossNotify(Payment $toss, array $params) {
    $GLOBALS['httpAttempts'] = []; $GLOBALS['logs'] = [];
    $result = null; $exc = '';
    try { $result = (new PaymentService('TossPayments', $toss->id))->notify($params); }
    catch (\Throwable $e) { $exc = class_basename($e) . ': ' . substr($e->getMessage(), 0, 160); }
    $attempts = $GLOBALS['httpAttempts'];
    $secret = str_contains(implode(' ', $GLOBALS['logs']) . $exc, 'TOSS_SECRET_KEY');
    $v = ($attempts || str_contains($exc, 'without a matching fake')) ? 'CONFIRM_CALLED' : ($result ? 'ACCEPTED' : ($secret ? 'SECRET_STAGE' : 'REJECTED'));
    $d = 'result=' . substr(redact(json_encode($result)), 0, 120) . ($exc ? ' exc=' . redact($exc) : '')
       . ' http_attempts=' . count($attempts) . ($GLOBALS['logs'] ? ' logs=' . implode(' | ', $GLOBALS['logs']) : '');
    return [$v, $d];
}
try { App\Services\Plugin\HookManager::remove('order.after_create'); } catch (\Throwable $e) {}
Event::listen(RequestSending::class, function ($e) { $GLOBALS['httpAttempts'][] = $e->request->method() . ' ' . preg_replace('/\?.*/', '', $e->request->url()); });
Event::listen(MessageLogged::class, function ($e) { $GLOBALS['logs'][] = strtoupper($e->level) . ' ' . substr(redact($e->message), 0, 200); });

$toss = Payment::where('payment', 'TossPayments')->where('enable', 1)->whereRaw('UPPER(currency) = ?', ['KRW'])->orderBy('id')->first();
$plan = null; $period = null;
foreach (Plan::where('sell', 1)->get() as $p) {
    foreach ((array) ($p->prices_krw ?? []) as $k => $v) {
        if (is_numeric($v) && $v > 0 && is_numeric(optional($p->prices)[$k] ?? null) && optional($p->prices)[$k] > 0) { $plan = $p; $period = $k; break 2; }
    }
}
check('setup: enabled KRW TossPayments payment exists', (bool) $toss, $toss ? "payment_id={$toss->id}" : '');
check('setup: sellable plan with independent CNY and KRW prices', (bool) $plan, $plan ? "plan={$plan->id} period=$period" : '');
$cancelledBefore = json_encode(optional(Order::where('trade_no', CANCELLED)->first())->getAttributes());

if ($toss && $plan) {
    $krw = (int) $plan->prices_krw[$period];
    $cnyAmt = (int) round($plan->prices[$period] * 100);
    Http::preventStrayRequests();
    DB::beginTransaction();
    try {
        // Temporary rows (rolled back). The CNY row only stands in for a future WeChat/Alipay
        // row so CNY legacy behaviour can be exercised; no gateway is called with it.
        $cny  = Payment::create(['uuid' => Str::lower(Str::random(32)), 'payment' => 'AlipayF2F', 'name' => 'rollback-test CNY', 'config' => [], 'enable' => 1, 'currency' => 'CNY']);
        $krw2 = Payment::create(['uuid' => Str::lower(Str::random(32)), 'payment' => 'TossPayments', 'name' => 'rollback-test KRW#2', 'config' => [], 'enable' => 1, 'currency' => 'KRW']);
        $tossCny = Payment::create(['uuid' => Str::lower(Str::random(32)), 'payment' => 'TossPayments', 'name' => 'rollback-test Toss-misconfigured-CNY', 'config' => [], 'enable' => 1, 'currency' => 'CNY']);
        $coupon = Coupon::create(['code' => 'RBTEST' . Str::upper(Str::random(8)), 'name' => 'rollback-test', 'type' => 2, 'value' => 10, 'show' => 1,
                                  'started_at' => time() - 60, 'ended_at' => time() + 3600]);
        $u = new User();
        $u->email = 'rollback-test-' . Str::random(10) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT);
        $u->uuid = (string) Str::uuid();
        $u->token = Str::random(32);
        $u->balance = 0;
        $u->save();
        $u = User::find($u->id);
        $inviterId = User::where('id', '<>', $u->id)->value('id');
        $reset = function ($bal = 0) use ($u) { Order::where('user_id', $u->id)->delete(); $u->balance = $bal; $u->invite_user_id = null; $u->save(); };

        // ---------- [Toss KRW-only] order creation: currency comes from Payment(payment_id) ----------
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id]);
        $db = $o ? Order::find($o->id) : null;
        check('R1 [KRW] Payment.currency=KRW -> prices_krw; payment_id/currency/total_amount snapshot',
            $db && cur($db) === 'KRW' && (int) $db->total_amount === $krw && (int) $db->payment_id === $toss->id,
            $db ? "currency={$db->currency} total={$db->total_amount} (expect $krw) payment_id={$db->payment_id}" : $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id, 'currency' => 'CNY', 'total_amount' => 1, 'amount' => 1, 'price' => 1]);
        check('R2 [KRW] client currency/amount/price/total_amount ignored', $o && cur($o) === 'KRW' && (int) $o->total_amount === $krw,
            $o ? "currency={$o->currency} total={$o->total_amount}" : $err);

        // ---------- [CNY legacy regression] ----------
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id, 'currency' => 'KRW', 'total_amount' => 1]);
        check('R3 [CNY legacy regression] client currency/total_amount ignored on CNY row', $o && cur($o) === 'CNY' && (int) $o->total_amount === $cnyAmt,
            $o ? "currency={$o->currency} total={$o->total_amount} (expect $cnyAmt)" : $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        check('R4 [CNY legacy regression] CNY row -> Plan.prices*100, payment_id stored', $o && cur($o) === 'CNY' && (int) $o->total_amount === $cnyAmt && (int) $o->payment_id === $cny->id,
            $o ? "currency={$o->currency} total={$o->total_amount} payment_id={$o->payment_id}" : $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period]);
        check('R5 [CNY legacy regression] order without payment_id stays CNY prices*100', $o && in_array(cur($o), ['CNY', ''], true) && (int) $o->total_amount === $cnyAmt,
            $o ? "currency={$o->currency} total={$o->total_amount}" : $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => 99999999]);
        check('R6 unknown payment_id refused', $o === null, $o ? "order created currency={$o->currency}" : $err);
        $reset();
        $cny->enable = 0; $cny->save();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        check('R7 disabled payment_id refused', $o === null, $o ? "order created currency={$o->currency}" : $err);
        $cny->enable = 1; $cny->save();
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id, 'coupon_code' => $coupon->code]);
        check('R8 [CNY legacy regression] coupon still applies to CNY orders', $o && (int) $o->discount_amount > 0 && (int) $o->coupon_id === $coupon->id,
            $o ? "discount={$o->discount_amount} coupon_id={$o->coupon_id} total={$o->total_amount}" : $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id, 'coupon_code' => $coupon->code]);
        check('R9 [KRW] percentage coupon now applies to KRW orders (coupon phase)', $o && (int) $o->discount_amount === intdiv($krw * 10, 100) && (int) $o->total_amount === $krw - intdiv($krw * 10, 100), $o ? "discount={$o->discount_amount} total={$o->total_amount}" : $err);
        $reset(1000000);
        $u->invite_user_id = $inviterId; $u->save();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id]);
        check('R10 [KRW] CNY balance not used', $o && (int) $o->total_amount === $krw && !(int) $o->balance_amount && (int) User::find($u->id)->balance === 1000000,
            $o ? "total={$o->total_amount} balance_amount={$o->balance_amount} user_balance=" . User::find($u->id)->balance : $err);
        check('R11 [KRW] no CNY invite commission', $o && !(int) $o->commission_balance && $o->invite_user_id === null,
            $o ? "commission_balance={$o->commission_balance} order.invite_user_id=" . var_export($o->invite_user_id, true) : $err);
        $reset();
        $u->invite_user_id = $inviterId; $u->save();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        if ($inviterId) check('R11b [CNY legacy regression] CNY order keeps invite commission', $o && (int) $o->invite_user_id === (int) $inviterId,
            $o ? "commission_balance={$o->commission_balance} order.invite_user_id={$o->invite_user_id}" : $err);
        $reset(1);
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        check('R12 [CNY legacy regression] CNY still uses balance', $o && (int) $o->balance_amount === 1 && (int) $o->total_amount === $cnyAmt - 1,
            $o ? "balance_amount={$o->balance_amount} total={$o->total_amount}" : $err);

        // ---------- checkout(): gateway currency must equal Order.currency ----------
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        if ($o) { $before = $o->payment_id; $GLOBALS['httpAttempts'] = []; $st = checkoutAs($u, $o, $toss->id); $o->refresh();
            check('R13 [Toss KRW-only] checkout refuses Toss for a CNY order', (int) $o->payment_id === (int) $before && !$GLOBALS['httpAttempts'], "response=$st payment_id={$o->payment_id}");
        } else check('R13 [Toss KRW-only] checkout refuses Toss for a CNY order', false, $err);
        $reset();
        [$o, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id]);
        if ($o) { $before = $o->payment_id; $GLOBALS['httpAttempts'] = []; $st = checkoutAs($u, $o, $cny->id); $o->refresh();
            check('R14 checkout refuses a CNY gateway for a KRW order', (int) $o->payment_id === (int) $before && !$GLOBALS['httpAttempts'], "response=$st payment_id={$o->payment_id}");
        } else check('R14 checkout refuses a CNY gateway for a KRW order', false, $err);

        // ---------- [Toss KRW-only] notify(): all guards BEFORE the confirm request ----------
        $pk = 'tgen_rollbacktest0000000000';
        $reject = function ($name, $params, $extra = '', $row = null) use ($toss) {
            [$r, $d] = tossNotify($row ?: $toss, $params);
            check("$name -> rejected before secret/confirm, 0 HTTP attempts", $r === 'REJECTED', "$r $d $extra");
        };
        $reset();
        [$ok, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $toss->id]);
        if (!$ok) { check('N setup: pending KRW order', false, $err); } else {
            $reject('N1 [Toss KRW-only] amount != Order.total_amount', ['paymentKey' => $pk, 'orderId' => $ok->trade_no, 'amount' => (string) ($ok->total_amount + 1)]);
            $reject('N2 [Toss KRW-only] unknown order', ['paymentKey' => $pk, 'orderId' => 'NO-SUCH-ORDER-' . Str::random(6), 'amount' => (string) $ok->total_amount]);
            [$r, $d] = tossNotify($toss, ['paymentKey' => $pk, 'orderId' => $ok->trade_no, 'amount' => (string) $ok->total_amount]);
            check('N3 [Toss KRW-only] pending KRW order + exact amount passes every guard and reaches the (blocked) confirm request', $r === 'CONFIRM_CALLED', "$r $d");
            // N6b/c: same valid order, but not pending (fixture rows only, rolled back)
            foreach ([Order::STATUS_PROCESSING => 'processing', Order::STATUS_COMPLETED => 'completed', Order::STATUS_CANCELLED => 'cancelled'] as $st => $label) {
                Order::where('id', $ok->id)->update(['status' => $st]);
                $reject("N6 [Toss KRW-only] $label order (status=$st)", ['paymentKey' => $pk, 'orderId' => $ok->trade_no, 'amount' => (string) $ok->total_amount]);
            }
            Order::where('id', $ok->id)->update(['status' => Order::STATUS_PENDING]);
            $reject('N7 [Toss KRW-only] Toss row whose currency is not KRW', ['paymentKey' => $pk, 'orderId' => $ok->trade_no, 'amount' => (string) $ok->total_amount], '', $tossCny);
            Order::where('id', $ok->id)->delete();
        }
        $reset();
        [$o2, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $krw2->id]);
        if ($o2) $reject('N4 [Toss KRW-only] Order.payment_id != this Toss payment', ['paymentKey' => $pk, 'orderId' => $o2->trade_no, 'amount' => (string) $o2->total_amount], "order.payment_id={$o2->payment_id} toss={$toss->id}");
        else check('N4 setup', false, $err);
        $reset();
        [$o3, $err] = saveOrder($u, ['plan_id' => $plan->id, 'period' => $period, 'payment_id' => $cny->id]);
        if ($o3) { $o3->payment_id = $toss->id; $o3->save(); // worst case: CNY order pointed at Toss
            $reject('N5 [Toss KRW-only] Order.currency != KRW', ['paymentKey' => $pk, 'orderId' => $o3->trade_no, 'amount' => (string) $o3->total_amount], "order.currency={$o3->currency}");
        } else check('N5 setup', false, $err);
        $c = Order::where('trade_no', CANCELLED)->first();
        if ($c) $reject('N6 [Toss KRW-only] existing cancelled order ' . CANCELLED, ['paymentKey' => $pk, 'orderId' => CANCELLED, 'amount' => (string) $c->total_amount], "status={$c->status}");
    } catch (\Throwable $e) {
        check('test run', false, class_basename($e) . ': ' . redact($e->getMessage()) . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        DB::rollBack();
    }
    $left = Payment::where('name', 'like', 'rollback-test%')->count() + Coupon::where('code', 'like', 'RBTEST%')->count() + User::where('email', 'like', 'rollback-test-%')->count();
    check('rollback: no temporary Payment/Coupon/User rows left', $left === 0, "leftover=$left");
}
check('cancelled order ' . CANCELLED . ' untouched', json_encode(optional(Order::where('trade_no', CANCELLED)->first())->getAttributes()) === $cancelledBefore);
echo ($fails ? "RESULT FAIL ($fails)" : 'RESULT PASS') . PHP_EOL;
exit($fails ? 1 : 0);
