<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\Helper;
use App\Utils\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with('payment')
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order['plan']) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return $this->success(OrderResource::make($order));
    }

    public function save(OrderSave $request)
    {
        // ManduVPN: a selected payment method must exist and be enabled.
        // No payment_id keeps the legacy CNY behaviour.
        if ($request->filled('payment_id')
            && !Payment::where('id', $request->input('payment_id'))->where('enable', 1)->exists()) {
            throw new ApiException(__('Payment method is not available'));
        }
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string',
            'payment_id' => 'nullable|integer|exists:App\Models\Payment,id',
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        // Validate plan purchase
        $planService->validatePurchase($user, $request->input('period'));

        // ManduVPN dual-currency: currency is SERVER-derived from the
        // chosen Payment gateway's `currency` column — never taken from a
        // client-supplied currency/amount parameter (Payment.currency is
        // itself admin-managed data, not something the client can set).
        // No payment_id => CNY, matching every pre-existing gateway before
        // this session's dual-currency work.
        $payment = $request->input('payment_id') ? Payment::find($request->input('payment_id')) : null;
        $currency = $payment ? strtoupper($payment->currency ?? 'CNY') : 'CNY';

        // KRW orders are deliberately barred from coupon codes: a
        // fixed-amount coupon's `value` has no currency field of its own
        // (see CouponService::use() / the Coupon model), so applying it
        // against a KRW total would silently subtract a CNY-scaled number
        // from a KRW amount. Rejecting the combination is safer than
        // guessing a conversion.
        // ManduVPN coupon phase: KRW coupons are validated by CouponService (currency-aware).

        return DB::transaction(function () use ($request, $plan, $user, $userService, $planService, $currency, $payment) {
            $period = $request->input('period');
            $newPeriod = PlanService::getPeriodKey($period);

            if ($currency === 'KRW') {
                $krwPrice = optional($plan->prices_krw)[$newPeriod] ?? null;
                if ($krwPrice === null) {
                    throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
                }
                $totalAmount = Money::toMinorUnit('KRW', $krwPrice);
            } else {
                // Unchanged legacy math — CNY fen, byte-identical to before this session.
                $totalAmount = optional($plan->prices)[$newPeriod] * 100;
            }

            // Create order — payment_id/currency/total_amount are all
            // fixed as a snapshot here. Later admin changes to Plan prices
            // or Payment.currency never retroactively affect this order.
            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'payment_id' => $payment?->id,
            ]);

            // Apply coupon if provided (CNY path only, per the guard above)
            if ($request->input('coupon_code')) {
                $this->applyCoupon($order, $request->input('coupon_code'));
            }

            // Set order attributes
            $orderService = new OrderService($order);
            $orderService->setVipDiscount($user); // percentage-based — currency-agnostic, safe for both
            $orderService->setOrderType($user);
            $orderService->setInvite($user);

            // Handle user balance — User.balance is a single CNY-denominated
            // field (no dual balance). Applying it to a KRW order would mix
            // currencies exactly like the coupon case above, so it's
            // skipped for KRW orders.
            if ($currency !== 'KRW' && $user->balance && $order->total_amount > 0) {
                $this->handleUserBalance($order, $user, $userService);
            }

            // ManduVPN: invite commission is CNY-only (commission_balance and user balance have
            // no currency column), so a KRW order never creates or triggers a commission.
            $manduPaymentId = $order->payment_id ?: $request->input('payment_id');
            if (strtoupper((string) $order->currency) === 'KRW'
                || ($manduPaymentId && strtoupper((string) optional(Payment::find($manduPaymentId))->currency) === 'KRW')) {
                $order->commission_balance = 0;
                $order->invite_user_id = null;
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }
            HookManager::call('order.after_create', $order);

            return $this->success($order->trade_no);
        });
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        // ManduVPN PP-A: re-check the plan audience at checkout - partner status may have been revoked after
        // this pending order was created. Refused before any gateway call or state change.
        $checkoutPlan = Plan::find($order->plan_id);
        if ($checkoutPlan && !PlanService::canUserPayPlan(User::find($order->user_id), $checkoutPlan)) {
            return $this->fail([403, __('This subscription plan is not available for your account')]);
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no))
                return $this->fail([400, '支付失败']);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1)
            return $this->fail([400, __('Payment method is not available')]);

        // ManduVPN: checkout NEVER re-derives price/currency — it only
        // verifies the gateway picked at checkout time is the SAME one the
        // order was created with (payment_id snapshot from save()), and
        // that gateway's CURRENT currency still matches the order's
        // snapshotted currency. If an admin changes Payment.currency after
        // the order was created, this order is refused rather than
        // silently charged in a different currency than the customer saw.
        if ($order->payment_id !== null && (int) $order->payment_id !== (int) $payment->id) {
            return $this->fail([400, __('This payment method does not match the order')]);
        }
        $orderCurrency = strtoupper($order->currency ?? 'CNY');
        $gatewayCurrency = strtoupper($payment->currency ?? 'CNY');
        if ($orderCurrency !== $gatewayCurrency) {
            return $this->fail([400, __('This payment method does not support the order currency')]);
        }

        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save())
            return $this->fail([400, __('Request failed, please try again later')]);
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'uuid',
            'currency',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return $this->success($methods);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }
}
