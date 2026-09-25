$out = [];

// PlanResource live test: existing plan id=1 must keep identical legacy fields
$plan1 = \App\Models\Plan::find(1);
$resource1 = (new \App\Http\Resources\PlanResource($plan1))->toArray(request());
$out['plan1_month_price'] = $resource1['month_price'] ?? 'MISSING_KEY';
$out['plan1_has_days_30_key'] = array_key_exists('days_30', $resource1);
$out['plan1_days_30_value'] = $resource1['days_30'] ?? 'MISSING_KEY';
$out['plan1_id'] = $resource1['id'] ?? 'MISSING_KEY';
$out['plan1_name'] = $resource1['name'] ?? 'MISSING_KEY';
$out['plan1_total_keys'] = count($resource1);

// Synthetic plan (in-memory only, never saved) with mixed legacy + days_* prices
$synthetic = new \App\Models\Plan([
    'id' => 999999, 'name' => 'synthetic', 'group_id' => 1, 'transfer_enable' => 100,
    'speed_limit' => null, 'device_limit' => 3, 'show' => true, 'sell' => true,
    'reset_traffic_method' => 0, 'capacity_limit' => null, 'sort' => 1,
    'prices' => ['monthly' => 10, 'days_30' => 45, 'days_360' => 360],
]);
$resourceS = (new \App\Http\Resources\PlanResource($synthetic))->toArray(request());
$out['synthetic_month_price'] = $resourceS['month_price'] ?? 'MISSING_KEY';
$out['synthetic_days_30'] = $resourceS['days_30'] ?? 'MISSING_KEY';
$out['synthetic_days_360'] = $resourceS['days_360'] ?? 'MISSING_KEY';
$out['synthetic_days_90_null'] = array_key_exists('days_90', $resourceS) && $resourceS['days_90'] === null;
$out['synthetic_all_keys'] = array_keys($resourceS);

// Notice CRUD test, rolled back (no popup key sent — matches new frontend contract)
\Illuminate\Support\Facades\DB::beginTransaction();
try {
    $notice = \App\Models\Notice::create([
        'title' => '__manduvpn_test_notice__',
        'content' => 'test content',
        'img_url' => null,
        'tags' => ['test'],
        'show' => false,
    ]);
    $out['notice_created_id'] = $notice->id;

    $notice2 = \App\Models\Notice::create([
        'title' => '__manduvpn_test_notice_2__',
        'content' => 'second',
        'tags' => [],
        'show' => false,
    ]);

    // update (edit)
    \App\Models\Notice::find($notice->id)->update(['title' => 'updated title', 'content' => 'updated content', 'tags' => ['a','b'], 'show' => false]);
    $out['notice_updated_title'] = \App\Models\Notice::find($notice->id)->title;

    // show toggle
    $n = \App\Models\Notice::find($notice->id);
    $n->show = $n->show ? 0 : 1;
    $n->save();
    $out['notice_show_after_toggle'] = (bool) \App\Models\Notice::find($notice->id)->show;

    // sort
    \App\Models\Notice::find($notice->id)->update(['sort' => 2]);
    \App\Models\Notice::find($notice2->id)->update(['sort' => 1]);
    $out['notice_sort_order'] = \App\Models\Notice::orderBy('sort', 'ASC')->whereIn('id', [$notice->id, $notice2->id])->pluck('id')->all();

    // delete
    $deleted = \App\Models\Notice::find($notice->id)->delete();
    $out['notice_deleted'] = (bool) $deleted;
    $out['notice_gone_after_delete'] = \App\Models\Notice::find($notice->id) === null;

    $out['notice_no_exception'] = true;
} catch (\Throwable $e) {
    $out['notice_no_exception'] = false;
    $out['notice_exception'] = $e->getMessage();
}
\Illuminate\Support\Facades\DB::rollBack();

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
