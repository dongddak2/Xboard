$out = [];
\Illuminate\Support\Facades\DB::beginTransaction();
try {
    $plan = \App\Models\Plan::create([
        'name' => '__manduvpn_v2_test__', 'group_id' => 1, 'transfer_enable' => 999999,
        'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false, 'renew' => true,
        'sort' => 999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['days_30' => 45], 'prices_krw' => ['days_30' => 9000],
    ]);
    $toss = \App\Models\Payment::where('payment', 'TossPayments')->first();

    // Simulate OrderController::save() logic directly for KRW via payment_id
    $totalAmountKrw = \App\Utils\Money::toMinorUnit('KRW', $plan->prices_krw['days_30']);
    $orderKrw = \App\Models\Order::create([
        'user_id' => 1, 'plan_id' => $plan->id, 'period' => 'days_30',
        'trade_no' => 'V2TEST1' . uniqid(), 'total_amount' => $totalAmountKrw, 'currency' => 'KRW',
        'payment_id' => $toss->id, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
    ]);
    $out['krw_order_snapshot'] = ['amount' => $orderKrw->total_amount, 'currency' => $orderKrw->currency, 'payment_id' => $orderKrw->payment_id];

    // Change Plan KRW price AFTER order creation
    $plan->prices_krw = ['days_30' => 8900];
    $plan->save();
    $orderKrw->refresh();
    $out['krw_order_after_price_change'] = $orderKrw->total_amount; // must still be 9000

    // New order after price change should use the NEW price
    $newTotalKrw = \App\Utils\Money::toMinorUnit('KRW', $plan->fresh()->prices_krw['days_30']);
    $out['new_krw_order_uses_new_price'] = $newTotalKrw; // expect 8900

    // CNY snapshot test
    $orderCny = \App\Models\Order::create([
        'user_id' => 1, 'plan_id' => $plan->id, 'period' => 'days_30',
        'trade_no' => 'V2TEST2' . uniqid(), 'total_amount' => \App\Utils\Money::toMinorUnit('CNY', $plan->prices['days_30']),
        'currency' => 'CNY', 'payment_id' => null, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
    ]);
    $out['cny_order_amount'] = $orderCny->total_amount; // expect 4500
    $plan->prices = ['days_30' => 49];
    $plan->save();
    $orderCny->refresh();
    $out['cny_order_after_price_change'] = $orderCny->total_amount; // must still be 4500

    // checkout() payment_id / currency guard simulation
    $gateway = new \App\Payments\TossPayments(['id' => $toss->id]);
    $out['notify_correct_payment_id'] = $gateway->notify(['orderId' => $orderKrw->trade_no, 'paymentKey' => 'fake', 'amount' => $totalAmountKrw]);
    // simulate a wrong payment_id on the order (as if a different gateway's order were replayed against Toss)
    $orderKrw->payment_id = 9999;
    $orderKrw->save();
    $out['notify_wrong_payment_id'] = $gateway->notify(['orderId' => $orderKrw->trade_no, 'paymentKey' => 'fake2', 'amount' => $totalAmountKrw]);
} finally {
    \Illuminate\Support\Facades\DB::rollBack();
}
echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
