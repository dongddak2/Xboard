$out = [];

$periods = \App\Models\Plan::getAvailablePeriods();
$out['period_key_count'] = count($periods);
$out['has_all_legacy'] = collect(['monthly','quarterly','half_yearly','yearly','two_yearly','three_yearly','onetime','reset_traffic'])
    ->every(fn($k) => isset($periods[$k]));
$out['has_all_days'] = collect(\App\Models\Plan::DAYS_BASED_PERIODS)->every(fn($d) => isset($periods["days_{$d}"]) && $periods["days_{$d}"]['days'] === $d);
$out['getPeriodKey_passthrough'] = \App\Services\PlanService::getPeriodKey('days_90') === 'days_90';
$out['isValidPeriod_days180'] = \App\Models\Plan::isValidPeriod('days_180');
$out['getPeriodDays_days360'] = \App\Models\Plan::getPeriodDays('days_360');

\Illuminate\Support\Facades\DB::beginTransaction();
$now = time();
try {
    $plan = \App\Models\Plan::create([
        'name' => '__manduvpn_test_plan__',
        'group_id' => 1,
        'transfer_enable' => 100,
        'speed_limit' => null,
        'device_limit' => 3,
        'show' => false,
        'sell' => false,
        'sort' => 999,
        'renew' => true,
        'prices' => ['days_30' => 45, 'days_90' => 122, 'days_360' => 360, 'monthly' => 10, 'yearly' => 100],
    ]);

    $userA = \App\Models\User::create([
        'email' => 'manduvpn_test_a_' . uniqid() . '@test.invalid',
        'password' => bcrypt('x'), 'uuid' => \Illuminate\Support\Str::uuid(),
        'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 0, 'banned' => 0, 'expired_at' => null,
    ]);
    $orderA = \App\Models\Order::create([
        'user_id' => $userA->id, 'plan_id' => $plan->id, 'period' => 'days_90',
        'trade_no' => 'TESTA' . uniqid(), 'total_amount' => 12200, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
        'status' => \App\Models\Order::STATUS_PROCESSING,
    ]);
    (new \App\Services\OrderService($orderA))->open();
    $userA->refresh();
    $out['A_days90_newpurchase_delta_days'] = round(($userA->expired_at - $now) / 86400, 3);
    $out['A_device_limit_copied'] = $userA->device_limit === 3;
    $out['A_group_id_copied'] = $userA->group_id === 1;

    $userB = \App\Models\User::create([
        'email' => 'manduvpn_test_b_' . uniqid() . '@test.invalid',
        'password' => bcrypt('x'), 'uuid' => \Illuminate\Support\Str::uuid(),
        'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 0, 'banned' => 0,
        'plan_id' => $plan->id,
        'expired_at' => $now + 30 * 86400,
    ]);
    $orderB = \App\Models\Order::create([
        'user_id' => $userB->id, 'plan_id' => $plan->id, 'period' => 'days_90',
        'trade_no' => 'TESTB' . uniqid(), 'total_amount' => 12200, 'type' => \App\Models\Order::TYPE_RENEWAL,
        'status' => \App\Models\Order::STATUS_PROCESSING,
    ]);
    (new \App\Services\OrderService($orderB))->open();
    $userB->refresh();
    $out['B_renewal_total_days_from_now'] = round(($userB->expired_at - $now) / 86400, 3);

    $userC = \App\Models\User::create([
        'email' => 'manduvpn_test_c_' . uniqid() . '@test.invalid',
        'password' => bcrypt('x'), 'uuid' => \Illuminate\Support\Str::uuid(),
        'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 0, 'banned' => 0, 'expired_at' => null,
    ]);
    $orderC = \App\Models\Order::create([
        'user_id' => $userC->id, 'plan_id' => $plan->id, 'period' => 'yearly',
        'trade_no' => 'TESTC' . uniqid(), 'total_amount' => 10000, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
        'status' => \App\Models\Order::STATUS_PROCESSING,
    ]);
    (new \App\Services\OrderService($orderC))->open();
    $userC->refresh();
    $out['C_yearly_delta_days'] = round(($userC->expired_at - $now) / 86400, 3);

    $userD = \App\Models\User::create([
        'email' => 'manduvpn_test_d_' . uniqid() . '@test.invalid',
        'password' => bcrypt('x'), 'uuid' => \Illuminate\Support\Str::uuid(),
        'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 0, 'banned' => 0, 'expired_at' => null,
    ]);
    $orderD = \App\Models\Order::create([
        'user_id' => $userD->id, 'plan_id' => $plan->id, 'period' => 'days_360',
        'trade_no' => 'TESTD' . uniqid(), 'total_amount' => 36000, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
        'status' => \App\Models\Order::STATUS_PROCESSING,
    ]);
    (new \App\Services\OrderService($orderD))->open();
    $userD->refresh();
    $out['D_days360_delta_days'] = round(($userD->expired_at - $now) / 86400, 3);

    $userE = \App\Models\User::create([
        'email' => 'manduvpn_test_e_' . uniqid() . '@test.invalid',
        'password' => bcrypt('x'), 'uuid' => \Illuminate\Support\Str::uuid(),
        'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 0, 'banned' => 0,
        'plan_id' => $plan->id,
        'expired_at' => $now + 200 * 86400,
    ]);
    \App\Models\Order::create([
        'user_id' => $userE->id, 'plan_id' => $plan->id, 'period' => 'monthly',
        'trade_no' => 'TESTE1' . uniqid(), 'total_amount' => 1000, 'balance_amount' => 0, 'surplus_amount' => 0,
        'refund_amount' => 0, 'type' => \App\Models\Order::TYPE_NEW_PURCHASE, 'status' => \App\Models\Order::STATUS_COMPLETED,
        'created_at' => $now - 10 * 86400,
    ]);
    \App\Models\Order::create([
        'user_id' => $userE->id, 'plan_id' => $plan->id, 'period' => 'days_60',
        'trade_no' => 'TESTE2' . uniqid(), 'total_amount' => 2000, 'balance_amount' => 0, 'surplus_amount' => 0,
        'refund_amount' => 0, 'type' => \App\Models\Order::TYPE_RENEWAL, 'status' => \App\Models\Order::STATUS_COMPLETED,
        'created_at' => $now - 5 * 86400,
    ]);
    $plan2 = \App\Models\Plan::create([
        'name' => '__manduvpn_test_plan2__', 'group_id' => 1, 'transfer_enable' => 200,
        'speed_limit' => null, 'device_limit' => 5, 'show' => false, 'sell' => false, 'sort' => 998,
        'renew' => true, 'prices' => ['days_90' => 122],
    ]);
    $orderE = new \App\Models\Order([
        'user_id' => $userE->id, 'plan_id' => $plan2->id, 'period' => 'days_90',
        'trade_no' => 'TESTE3' . uniqid(), 'total_amount' => 12200,
    ]);
    $svcE = new \App\Services\OrderService($orderE);
    $exception = null;
    try {
        $svcE->setOrderType($userE);
    } catch (\Throwable $e) {
        $exception = $e->getMessage();
    }
    $out['E_surplus_no_exception'] = $exception === null;
    $out['E_surplus_exception_msg'] = $exception;
    $out['E_order_type'] = $orderE->type ?? null;
    $out['E_surplus_amount'] = $orderE->surplus_amount ?? null;

    \Illuminate\Support\Facades\DB::rollBack();
} catch (\Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    $out['FATAL_ERROR'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
