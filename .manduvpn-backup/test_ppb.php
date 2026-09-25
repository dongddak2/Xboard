<?php
// ManduVPN PP-B regression. Every row (plans, users, tokens, orders) lives in ONE transaction that is always
// rolled back. Real HTTP kernel; 500 is never a PASS; refusals must be 4xx. No outgoing HTTP is possible.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, User};
use App\Services\{AuthService, PlanService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function ppb_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
function ppb_note($n, $d) { echo "NOTE $n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
function ppb_req($http, $method, $uri, $auth, array $body = []) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
    $req = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
    $res = $http->handle($req);
    $exc = ($res->getStatusCode() >= 500 && !empty($res->exception)) ? ' EXC=' . class_basename($res->exception) . ': ' . substr($res->exception->getMessage(), 0, 140) : '';
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr($res->getContent(), 0, 160) . $exc];
}
function ppb_user($admin = false, $partner = false) {
    $u = new User(); $u->email = 'rollback-test-ppb-' . Str::lower(Str::random(10)) . '@example.invalid';
    $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
    if ($admin) $u->is_admin = 1;
    $u->is_partner = $partner ? 1 : 0; $u->save();
    $u = User::find($u->id);
    return [$u, (new AuthService($u))->generateAuthData()['auth_data']];
}
$route = function ($action) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action)); return $r ? '/' . $r->uri() : null; };
$R = ['userPlans' => $route('V1\User\PlanController@fetch'), 'guestPlans' => $route('V1\Guest\PlanController@fetch'), 'assign' => $route('Admin\OrderController@assign'),
      'userUpdate' => $route('Admin\UserController@update'), 'planSave' => $route('Admin\PlanController@save'), 'planUpdate' => $route('Admin\PlanController@update')];
ppb_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$groupId = (int) DB::table('v2_server_group')->orderBy('id')->value('id');
$legacy = DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

DB::beginTransaction();
try {
    $mk = function ($name, $aud, $tier, $devices = 2, $show = 1, $sell = 1) use ($groupId) {
        $p = new Plan(); $p->forceFill(['name' => "rollback-test-ppb $name", 'group_id' => $groupId, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => $devices,
            'show' => $show, 'sell' => $sell, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => $aud, 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $pubBasic = $mk('BASIC', 'public', 'basic'); $parBasic = $mk('BASIC PARTNER', 'partner', 'basic');
    $pubPro = $mk('PRO', 'public', 'pro'); $parPro = $mk('PRO PARTNER', 'partner', 'pro');
    $vip = $mk('VIP', 'admin_only', 'vip', 5, 0, 0); $vipShown = $mk('VIP-SHOWN', 'admin_only', 'vip', 5, 1, 1);
    [$admin, $adminAuth] = ppb_user(true);
    [$normal, $normalAuth] = ppb_user();
    [$partner, $partnerAuth] = ppb_user(false, true);
    $rows = fn ($j) => collect($j['data'] ?? [])->keyBy('id');

    // PPB-1 PlanResource exposes service_tier
    [$st, $j] = ppb_req($http, 'GET', $R['userPlans'], $normalAuth); $n = $rows($j);
    ppb_check('PPB-1 user plan/fetch returns service_tier from PlanResource', $st === 200 && ($n[$pubBasic->id]['service_tier'] ?? 'missing') === 'basic' && ($n[$pubPro->id]['service_tier'] ?? 'missing') === 'pro', "http=$st");
    ppb_check('PPB-1b audience is NOT exposed to the purchase API', $st === 200 && isset($n[$pubBasic->id]) && !array_key_exists('audience', $n[$pubBasic->id]));

    // PPB-13/14/15 visibility through the real fetch
    $pubIds = [$pubBasic->id, $pubPro->id]; $parIds = [$parBasic->id, $parPro->id]; $vipIds = [$vip->id, $vipShown->id];
    ppb_check('PPB-13 normal user receives the public plans only (no partner, no VIP)', $st === 200 && !array_diff($pubIds, $n->keys()->all()) && !array_intersect(array_merge($parIds, $vipIds), $n->keys()->all()), "http=$st");
    [$st, $j] = ppb_req($http, 'GET', $R['userPlans'], $partnerAuth); $p = $rows($j);
    ppb_check('PPB-14 partner user receives the partner counterparts instead of the same-tier public rows', $st === 200 && !array_diff($parIds, $p->keys()->all()) && !array_intersect($pubIds, $p->keys()->all())
        && ($p[$parBasic->id]['service_tier'] ?? '') === 'basic' && ($p[$parPro->id]['service_tier'] ?? '') === 'pro', "http=$st");
    [$sg, $jg] = ppb_req($http, 'GET', $R['guestPlans'], null); $g = $rows($jg);
    ppb_check('PPB-15 VIP/admin_only absent from user, partner and guest purchase fetch (even show=sell=1)', $st === 200 && $sg === 200 && !array_intersect($vipIds, array_merge($n->keys()->all(), $p->keys()->all(), $g->keys()->all())), "http=$st/$sg");

    // PPB-2/3/4 admin plan API
    $body = fn ($name, $extra) => ['name' => "rollback-test-ppb $name", 'transfer_enable' => 100, 'group_id' => $groupId, 'device_limit' => 2, 'capacity_limit' => null,
        'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'show' => 1, 'sell' => 1, 'renew' => 1] + $extra;
    $ok2 = true; $d2 = [];
    foreach ([['public', 'basic'], ['partner', 'pro'], ['admin_only', 'vip'], ['public', 'legacy']] as [$aud, $tier]) { // PLAN-ARCH-1A: + legacy accepted
        [$s, , $raw] = ppb_req($http, 'POST', $R['planSave'], $adminAuth, $body("API $aud $tier", ['audience' => $aud, 'service_tier' => $tier]));
        $row = Plan::where('name', "rollback-test-ppb API $aud $tier")->first();
        $ok2 = $ok2 && $s === 200 && $row && $row->audience === $aud && $row->service_tier === $tier; $d2[] = "$aud/$tier=$s";
        if ($s !== 200) $d2[] = $raw;
    }
    ppb_check('PPB-2 admin plan/save stores public/basic, partner/pro, admin_only/vip', $ok2, implode(' ', $d2));
    [$s3, , $r3] = ppb_req($http, 'POST', $R['planSave'], $adminAuth, $body('bad audience', ['audience' => 'hacker', 'service_tier' => 'basic']));
    ppb_check('PPB-3 invalid audience -> 422, nothing stored', $s3 === 422 && !Plan::where('name', 'rollback-test-ppb bad audience')->exists(), "http=$s3 $r3");
    [$s4, , $r4] = ppb_req($http, 'POST', $R['planSave'], $adminAuth, $body('bad tier', ['audience' => 'public', 'service_tier' => 'abc']));
    ppb_check('PPB-4 invalid service_tier -> 422, nothing stored', $s4 === 422 && !Plan::where('name', 'rollback-test-ppb bad tier')->exists(), "http=$s4 $r4");

    // PPB-5 capacity_limit null = unlimited / available; omitted = DB default 0 = sold out (the bug the UI now avoids)
    $apiPlan = Plan::where('name', 'rollback-test-ppb API public basic')->first();
    ppb_note('PPB-5 flags after plan/save create', 'show=' . var_export($apiPlan->show ?? null, true) . ' sell=' . var_export($apiPlan->sell ?? null, true) . ' (if false, save does not persist flags; the admin toggles use plan/update)');
    ppb_req($http, 'POST', $R['planUpdate'], $adminAuth, ['id' => $apiPlan->id, 'show' => 1]);
    ppb_req($http, 'POST', $R['planUpdate'], $adminAuth, ['id' => $apiPlan->id, 'sell' => 1]);
    $apiPlan = Plan::find($apiPlan->id);
    [$st, $j] = ppb_req($http, 'GET', $R['userPlans'], $normalAuth);
    ppb_check('PPB-5 capacity_limit=null via admin API is stored NULL, hasCapacity=true, plan is listed', $apiPlan->capacity_limit === null && (new PlanService($apiPlan))->hasCapacity($apiPlan) && $rows($j)->has($apiPlan->id),
        'capacity=' . var_export($apiPlan->capacity_limit, true) . " http=$st");
    $bodyNoCap = $body('no capacity field', ['audience' => 'public', 'service_tier' => 'basic']); unset($bodyNoCap['capacity_limit']);
    ppb_req($http, 'POST', $R['planSave'], $adminAuth, $bodyNoCap);
    $noCap = Plan::where('name', 'rollback-test-ppb no capacity field')->first();
    ppb_check('PPB-5b control: capacity_limit OMITTED -> DB default 0 -> sold out (why the UI must always send it)', $noCap && (int) $noCap->capacity_limit === 0 && !(new PlanService($noCap))->hasCapacity($noCap),
        'capacity=' . var_export($noCap->capacity_limit ?? 'missing', true));

    // PPB-6..10 admin user/update plan assignment
    $base = fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'is_admin' => 0, 'is_staff' => 0];
    $snap = fn (User $u) => json_encode(User::find($u->id)->only(['plan_id', 'group_id', 'expired_at', 'is_partner']));
    [$u6] = ppb_user(); $b6 = $snap($u6);
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u6) + ['plan_id' => $parPro->id]);
    ppb_check('PPB-6 normal user + partner plan via user/update -> 422, user unchanged', $s === 422 && $snap($u6) === $b6, "http=$s $raw");
    [$u7] = ppb_user();
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u7) + ['plan_id' => $parPro->id, 'is_partner' => 1]);
    $a7 = User::find($u7->id);
    ppb_check('PPB-7 is_partner=1 + partner plan in the same request -> 200, both stored, group from plan', $s === 200 && (int) $a7->is_partner === 1 && (int) $a7->plan_id === (int) $parPro->id && (int) $a7->group_id === (int) $parPro->group_id, "http=$s $raw");
    [$u8] = ppb_user(false, true); $b8 = $snap($u8);
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u8) + ['plan_id' => $parPro->id, 'is_partner' => 0]);
    ppb_check('PPB-8 is_partner=0 + partner plan in the same request -> 422, user unchanged (still partner)', $s === 422 && $snap($u8) === $b8, "http=$s $raw");
    [$u9] = ppb_user();
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u9) + ['plan_id' => $vip->id]);
    ppb_check('PPB-9 admin_only/VIP plan via user/update -> 200 (admin direct assignment), is_partner untouched', $s === 200 && (int) User::find($u9->id)->plan_id === (int) $vip->id && (int) User::find($u9->id)->is_partner === 0, "http=$s $raw");
    [$u10] = ppb_user(false, true); $exp = time() + 20 * 86400;
    User::where('id', $u10->id)->update(['plan_id' => $parPro->id, 'group_id' => $parPro->group_id, 'expired_at' => $exp]);
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u10) + ['is_partner' => 0]);
    $a10 = User::find($u10->id);
    ppb_check('PPB-10 changing only is_partner keeps plan_id / group_id / expired_at', $s === 200 && (int) $a10->is_partner === 0 && (int) $a10->plan_id === (int) $parPro->id && (int) $a10->group_id === (int) $parPro->group_id && (int) $a10->expired_at === $exp, "http=$s $raw");
    User::where('id', $u10->id)->update(['is_partner' => 1]);
    [$s, , $raw] = ppb_req($http, 'POST', $R['userUpdate'], $adminAuth, $base($u10) + ['is_partner' => 0, 'plan_id' => $parPro->id, 'expired_at' => $exp]);
    $a10 = User::find($u10->id);
    ppb_check('PPB-10b admin UI payload (same plan_id re-sent) while revoking partner -> 200, subscription unchanged', $s === 200 && (int) $a10->is_partner === 0 && (int) $a10->plan_id === (int) $parPro->id && (int) $a10->expired_at === $exp, "http=$s $raw");

    // PPB-16/17 admin order/assign with the exact frontend body
    [$u16] = ppb_user();
    [$s, $j, $raw] = ppb_req($http, 'POST', $R['assign'], $adminAuth, ['plan_id' => $vip->id, 'email' => $u16->email, 'period' => 'days_30', 'total_amount' => 0]);
    $o16 = is_string($j['data'] ?? null) ? Order::where('trade_no', $j['data'])->first() : null;
    ppb_check('PPB-16 order/assign with the frontend body {plan_id,email,period,total_amount} -> 200, pending VIP order', $s === 200 && $o16 && (int) $o16->plan_id === (int) $vip->id && (int) $o16->status === 0 && (int) $o16->total_amount === 0 && $o16->period === 'days_30', "http=$s $raw");
    [$u17] = ppb_user();
    [$s, , $raw] = ppb_req($http, 'POST', $R['assign'], $adminAuth, ['plan_id' => $parPro->id, 'email' => $u17->email, 'period' => 'days_30', 'total_amount' => 1000]);
    ppb_check('PPB-17 order/assign partner plan to a normal user -> 422 (PP-A), no order', $s === 422 && Order::where('user_id', $u17->id)->count() === 0, "http=$s $raw");
} catch (\Throwable $e) {
    ppb_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-ppb-%')->count() + Plan::where('name', 'like', 'rollback-test-ppb%')->count();
ppb_check('rollback: no temp users / plans left', $left === 0, "left=$left");
ppb_check('legacy plan rows 1-11 byte-identical', DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $legacy);
ppb_check('PPB-18 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
