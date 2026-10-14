<?php
use App\Models\Plan;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/PlanRules.php';
$apply = getenv('MANDU_PLAN_APPLY') === '1';
$expected = json_decode(file_get_contents(__DIR__.'/expected-prices.json'), true, 512, JSON_THROW_ON_ERROR);
DB::transaction(function () use ($apply, $expected) {
    foreach (['service_tier','audience','prices_krw','device_limit'] as $column) {
        if (!Schema::hasColumn('v2_plan', $column)) throw new RuntimeException('필수 요금제 컬럼 없음: '.$column);
    }
    if (!Schema::hasColumn('v2_user', 'assigned_server_id')) throw new RuntimeException('서버 배정 컬럼 없음');
    $plans = Plan::whereIn('id', range(2,11))->lockForUpdate()->get()->keyBy('id');
    if ($plans->count() !== 10) throw new RuntimeException('기존 상품 2~11을 확인할 수 없습니다');
    foreach ($plans as $id => $plan) {
        $device = $id <= 6 ? $id - 1 : $id - 6;
        $group = $id <= 6 ? 1 : 2;
        if ((int)$plan->device_limit !== $device || (int)$plan->group_id !== $group
            || !ManduPlanRules::samePrices($plan->prices ?? [], $expected[$id]['prices'])
            || !ManduPlanRules::samePrices($plan->prices_krw ?? [], $expected[$id]['prices_krw'])) {
            throw new RuntimeException('기존 상품 구성이 달라졌습니다. 변경 없이 중단: '.$id);
        }
        $tier = $id <= 6 ? 'basic' : 'pro';
        if ($plan->service_tier !== null && $plan->service_tier !== $tier) throw new RuntimeException('다른 tier 충돌: '.$id);
        if ($plan->audience !== null && $plan->audience !== 'public') throw new RuntimeException('비공개 상품 audience 충돌: '.$id);
    }
    $ike = Server::whereKey(3)->lockForUpdate()->firstOrFail();
    if ($ike->type !== 'ikev2' || !$ike->show || $ike->parent_id || $ike->host !== 'kr01.manduvpn.kr') {
        throw new RuntimeException('활성 KR01 IKEv2 노드 3을 확인해주세요');
    }
    $cyber = Server::whereKey(1)->firstOrFail();
    if ($cyber->type !== 'hysteria' || !$cyber->show || $cyber->host !== 'kr01.manduvpn.kr') {
        throw new RuntimeException('활성 KR01 Cyber 노드 1을 확인해주세요');
    }
    foreach ([$ike,$cyber] as $node) {
        $members = array_map('intval',(array)$node->group_ids);
        if (!in_array(1,$members,true) || !in_array(2,$members,true)) throw new RuntimeException('기존 프로·프리미엄 서버 그룹을 확인해주세요');
    }
    $groupName = 'BASIC · 단일 IKEv2';
    $group = DB::table('v2_server_group')->where('name',$groupName)->lockForUpdate()->first();
    foreach (range(1,5) as $devices) {
        $definition = ManduPlanRules::basic($devices);
        $matches = Plan::where('name',$definition['name'])->whereNotIn('id',range(2,6))->get();
        if ($matches->count() > 1) throw new RuntimeException('베이직 상품 이름 중복');
        $existing = $matches->first();
        // Old IDs 2..6 have the same names; those will become Pro below.
        if ($existing && !in_array((int)$existing->id,range(2,6),true)) {
            if (!$group || $existing->service_tier !== 'legacy' || (int)$existing->group_id !== (int)$group->id
                || (int)$existing->device_limit !== $devices || (int)$existing->transfer_enable !== 999999
                || $existing->audience !== 'public' || !$existing->show || !$existing->sell
                || !ManduPlanRules::samePrices($existing->prices ?? [],$definition['prices'])
                || !ManduPlanRules::samePrices($existing->prices_krw ?? [],$definition['prices_krw'])) {
                throw new RuntimeException('새 베이직 상품과 동일한 이름의 다른 설정이 있습니다');
            }
        }
    }
    foreach (Plan::where('service_tier','master')->get() as $master) {
        if ($master->audience !== 'admin_only' || $master->device_limit !== null || (int)$master->transfer_enable !== 999999) {
            throw new RuntimeException('기존 마스터 설정이 관리자 전용 무제한 정책과 다릅니다. 변경 없이 중단');
        }
    }
    echo ($apply ? 'APPLY' : 'DRY RUN').": 기존 가격·상품 ID·회원·주문 보존, 새 베이직 1~5대 추가\n";
    foreach (range(1,5) as $n) echo "베이직 {$n}대: 30일 ".(6000*$n)."원 / ".(30*$n)."위안, 999999GB\n";
    if (!$apply) return;
    $now = time();
    $groupId = $group ? (int)$group->id : DB::table('v2_server_group')->insertGetId(['name'=>$groupName,'created_at'=>$now,'updated_at'=>$now]);
    $groups = array_values(array_unique(array_map('intval',(array)$ike->group_ids)));
    if (!in_array($groupId,$groups,true)) { $groups[]=$groupId; $ike->group_ids=$groups; $ike->save(); }
    foreach ($plans as $id => $plan) {
        $device=$id<=6 ? $id-1 : $id-6;
        $plan->name=($id<=6 ? '프로' : '프리미엄').' · '.$device.'대';
        $plan->service_tier=$id<=6 ? 'basic' : 'pro';
        $plan->audience='public';
        $plan->save();
    }
    foreach (range(1,5) as $devices) {
        $definition=ManduPlanRules::basic($devices);
        if (Plan::where('name',$definition['name'])->exists()) continue;
        Plan::create($definition + ['group_id'=>$groupId,'show'=>1,'sell'=>1,'renew'=>1,'sort'=>$devices,
            'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY,'content'=>'IKEv2 서버 1대 자동 배정','capacity_limit'=>null,'speed_limit'=>null]);
    }
    // Keep the test plan and historical orders, but remove it from new sales.
    $test=Plan::whereKey(1)->where('name','테스트 플랜')->first();
    if ($test) { $test->show=0;$test->sell=0;$test->save(); }
    $master = Plan::where('service_tier','master')->first();
    if (!$master) Plan::create(ManduPlanRules::master() + ['group_id'=>2,'sort'=>999,'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY]);
    echo "요금제 적용 완료. 새 베이직 그룹 ID={$groupId}, 마스터는 관리자 지정 전용\n";
});
