$out = [];

// Real Plan id=2 (베이직 1대) via real API path — read-only
$plan2 = \App\Models\Plan::find(2);
$resource = (new \App\Http\Resources\PlanResource($plan2))->toArray(request());
$out['plan2_days_30_cny'] = $resource['days_30'];
$out['plan2_days_30_krw'] = $resource['days_30_krw'];
$out['plan2_days_360_cny'] = $resource['days_360'];
$out['plan2_days_360_krw'] = $resource['days_360_krw'];

// Independence test — rollback transaction, throwaway plan only
\Illuminate\Support\Facades\DB::beginTransaction();
try {
    $plan = \App\Models\Plan::create([
        'name' => '__manduvpn_currency_test__', 'group_id' => 1, 'transfer_enable' => 999999,
        'speed_limit' => null, 'device_limit' => 1, 'show' => false, 'sell' => false, 'renew' => true,
        'sort' => 999, 'reset_traffic_method' => 0, 'capacity_limit' => null, 'content' => null,
        'prices' => ['days_30' => 45], 'prices_krw' => ['days_30' => 9000],
    ]);
    $out['initial'] = ['cny' => $plan->prices['days_30'], 'krw' => $plan->prices_krw['days_30']];

    // Admin edits CNY only (49), simulating PlanController::save() with only 'prices' changed
    $plan->prices = ['days_30' => 49];
    $plan->save();
    $plan->refresh();
    $out['after_cny_only_edit'] = ['cny' => $plan->prices['days_30'], 'krw' => $plan->prices_krw['days_30']];

    // Admin edits KRW only (8900), simulating PlanController::save() with only 'prices_krw' changed
    $plan->prices_krw = ['days_30' => 8900];
    $plan->save();
    $plan->refresh();
    $out['after_krw_only_edit'] = ['cny' => $plan->prices['days_30'], 'krw' => $plan->prices_krw['days_30']];

    $out['cny_unaffected_by_krw_edit'] = $out['after_krw_only_edit']['cny'] === 49;
    $out['krw_unaffected_by_cny_edit'] = $out['after_cny_only_edit']['krw'] === 9000;
} finally {
    \Illuminate\Support\Facades\DB::rollBack();
}

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
