<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Models\Order;
use App\Utils\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TossPayments V2 (widgets) gateway, KRW only.
 *
 * Confirmed against the official docs this session
 * (docs.tosspayments.com/guides/v2/get-started/payment-flow,
 * .../payment-widget/integration-window, .../reference — payments/confirm):
 *   - Real payment initiation happens CLIENT-SIDE via the V2 widgets() SDK,
 *     not a server-built redirect URL like xboard's other gateways
 *     (EPay/AlipayF2F/...). pay() therefore returns `type => 2` (a new
 *     type, alongside the existing 0=qrcode/1=url) carrying the JSON the
 *     frontend needs to call `widgets.requestPayment()`.
 *   - The browser is redirected by Toss to `successUrl` with
 *     `paymentKey`/`orderId`/`amount` query params, which the frontend
 *     forwards, unmodified, to this gateway's existing `notify()` entry
 *     point (reusing `/v1/guest/payment/notify/{method}/{uuid}` — no new
 *     backend route).
 *   - `notify()` NEVER trusts the client-supplied `amount`: it looks up
 *     the real Order by `orderId` (== trade_no), verifies
 *     Order.currency === KRW and Order.total_amount === the returned
 *     amount exactly, and only THEN calls Toss's server-to-server confirm
 *     API using the server-only TOSS_SECRET_KEY. Any mismatch returns
 *     false without ever calling Toss — the confirm call is the sole
 *     point where money actually moves, so it is the one place this must
 *     be airtight.
 *   - paymentKey is stored via the existing generic `callback_no` column
 *     (verified this session: a plain nullable string column, already
 *     used this same way for every other gateway's confirmation
 *     reference) — no new column needed.
 */
class TossPayments implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'notice' => [
                'label' => '안내',
                'description' => 'Secret Key는 이 화면이 아니라 서버 .env의 TOSS_SECRET_KEY로만 관리됩니다.',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        return [
            'type' => 2, // 0=qrcode 1=url 2=client-side SDK params (ManduVPN extension)
            'data' => json_encode([
                'orderId' => $order['trade_no'],
                'amount' => (int) $order['total_amount'],
                'currency' => 'KRW',
                'orderName' => 'ManduVPN 구독 결제',
            ]),
        ];
    }

    public function notify($params): array|bool
    {
        $orderId = $params['orderId'] ?? null;
        $paymentKey = $params['paymentKey'] ?? null;
        $returnedAmount = isset($params['amount']) ? (int) $params['amount'] : null;

        if (!$orderId || !$paymentKey || $returnedAmount === null) {
            Log::warning('TossPayments notify: missing required params', ['params' => $params]);
            return false;
        }

        $order = Order::where('trade_no', $orderId)->first();
        // ManduVPN: only a pending order of an enabled KRW Toss row may reach the secret
        // check and the Toss confirm request; cancelled/completed/processing are refused here.
        $manduTossRow = \App\Models\Payment::find($this->config['id'] ?? null);
        if (!$order || (int) $order->status !== \App\Models\Order::STATUS_PENDING
            || !$manduTossRow || strtoupper((string) $manduTossRow->currency) !== 'KRW') {
            \Illuminate\Support\Facades\Log::warning('TossPayments notify: order not payable', ['trade_no' => $order->trade_no ?? null, 'status' => $order->status ?? null]);
            return false;
        }
        if (!$order) {
            Log::warning('TossPayments notify: order not found', ['orderId' => $orderId]);
            return false;
        }

        if (strtoupper($order->currency ?? '') !== 'KRW') {
            Log::warning('TossPayments notify: order currency is not KRW', ['orderId' => $orderId, 'currency' => $order->currency]);
            return false;
        }

        // ManduVPN: the order must have been created (or checked out)
        // against THIS SAME Payment row — not merely "some KRW gateway".
        // $this->config['id'] is populated by PaymentService's constructor
        // from the real v2_payment row this gateway instance was built for.
        $thisPaymentId = $this->config['id'] ?? null;
        if ($thisPaymentId !== null && (int) $order->payment_id !== (int) $thisPaymentId) {
            Log::warning('TossPayments notify: order.payment_id does not match this gateway', [
                'orderId' => $orderId, 'order_payment_id' => $order->payment_id, 'this_payment_id' => $thisPaymentId,
            ]);
            return false;
        }

        // Authoritative amount is the DB order, never the caller's value —
        // this check runs BEFORE any call to Toss and BEFORE any money moves.
        if ($returnedAmount !== (int) $order->total_amount) {
            Log::warning('TossPayments notify: amount mismatch, refusing to confirm', [
                'orderId' => $orderId, 'db_amount' => $order->total_amount, 'returned_amount' => $returnedAmount,
            ]);
            return false;
        }

        // ManduVPN PP-A2: re-check partner eligibility before Toss confirm. A partner-only pending order must not
        // be captured after partner status was revoked (the widget path never passes through /order/checkout).
        // Public / admin_only (VIP) plans are unaffected; a missing plan keeps the existing behaviour.
        $manduPlan = \App\Models\Plan::find($order->plan_id);
        if ($manduPlan && !\App\Services\PlanService::canUserPayPlan(\App\Models\User::find($order->user_id), $manduPlan)) {
            \Illuminate\Support\Facades\Log::warning('TossPayments notify: partner plan not payable', ['trade_no' => $order->trade_no]);
            return false;
        }

        $secretKey = config('services.toss.secret_key');
        if (!$secretKey) {
            Log::error('TossPayments notify: TOSS_SECRET_KEY is not configured');
            return false;
        }
        if (str_starts_with($secretKey, 'live_')) {
            // Safety rail: never allow a live key to be used from this
            // sandbox integration path.
            Log::error('TossPayments notify: refusing to run with a live_ secret key');
            return false;
        }

        $response = Http::withBasicAuth($secretKey, '')
            ->post('https://api.tosspayments.com/v1/payments/confirm', [
                'paymentKey' => $paymentKey,
                'orderId' => $orderId,
                'amount' => $returnedAmount,
            ]);

        if (!$response->successful()) {
            Log::warning('TossPayments confirm failed', ['orderId' => $orderId, 'status' => $response->status(), 'body' => $response->body()]);
            return false;
        }

        $data = $response->json();
        if (($data['status'] ?? null) !== 'DONE') {
            Log::warning('TossPayments confirm returned non-DONE status', ['orderId' => $orderId, 'status' => $data['status'] ?? null]);
            return false;
        }

        return [
            'trade_no' => $orderId,
            'callback_no' => $paymentKey,
        ];
    }
}
