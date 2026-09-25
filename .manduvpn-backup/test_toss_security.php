$out = [];
\Illuminate\Support\Facades\DB::beginTransaction();
try {
    $plan = \App\Models\Plan::create([
        'name' => '__manduvpn_toss_test_plan__', 'group_id' => 1, 'transfer_enable' => 999999,
        'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false, 'renew' => true,
        'sort' => 999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['days_30' => 45], 'prices_krw' => ['days_30' => 9000],
    ]);
    $user = \App\Models\User::create([
        'email' => 'toss_sec_test_' . uniqid() . '@test.invalid', 'password' => bcrypt('x'),
        'uuid' => \Illuminate\Support\Str::uuid(), 'token' => \Illuminate\Support\Str::random(32),
        'transfer_enable' => 0, 'banned' => 0, 'expired_at' => null,
    ]);
    $order = \App\Models\Order::create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'days_30',
        'trade_no' => 'TOSSSEC' . uniqid(), 'total_amount' => 9000, 'currency' => 'KRW', 'status' => 0,
        'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
    ]);

    $gateway = new \App\Payments\TossPayments([]);

    // Case 1: amount too low
    $out['tampered_low'] = $gateway->notify(['orderId' => $order->trade_no, 'paymentKey' => 'fake_pk_1', 'amount' => 1]);
    // Case 2: amount too high
    $out['tampered_high'] = $gateway->notify(['orderId' => $order->trade_no, 'paymentKey' => 'fake_pk_2', 'amount' => 9001]);
    // Case 3: correct amount, but order currency swapped to CNY beforehand
    $order2 = \App\Models\Order::create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'days_30',
        'trade_no' => 'TOSSSEC2' . uniqid(), 'total_amount' => 4500, 'currency' => 'CNY', 'status' => 0,
        'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
    ]);
    $out['cny_order_via_toss'] = $gateway->notify(['orderId' => $order2->trade_no, 'paymentKey' => 'fake_pk_3', 'amount' => 4500]);
    // Case 4: order not found
    $out['unknown_order'] = $gateway->notify(['orderId' => 'DOES_NOT_EXIST', 'paymentKey' => 'fake_pk_4', 'amount' => 9000]);
    // Case 5: missing params
    $out['missing_params'] = $gateway->notify(['orderId' => $order->trade_no]);

    $order->refresh();
    $out['order_currency'] = $order->currency;
    $out['order_amount'] = $order->total_amount;
} finally {
    \Illuminate\Support\Facades\DB::rollBack();
}
echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
