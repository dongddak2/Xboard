<?php
// ManduVPN PLAN-ARCH-1A regression: service_tier semantics. legacy = one assigned IKEv2 server (BSA); basic / pro / master =
// plain group entitlement; vip = admin_only; partner = audience only. ONE rolled-back transaction with temporary groups,
// servers, plans, users and orders; real rows are never in scope. No node contact, no payment, no Toss.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Exceptions\ApiException;
use App\Models\{Order, Plan, Server, User};
use App\Services\{AuthService, BasicServerAssignmentService as BSA, OrderService, ServerService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function a_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['planSave' => $route('Admin\PlanController@save'), 'userPlans' => $route('V1\User\PlanController@fetch'), 'guestPlans' => $route('V1\Guest\PlanController@fetch'),
      'assign' => $route('Admin\OrderController@assign')];
a_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
a_check('A0 single-server tier constant is legacy', BSA::TIER === 'legacy', BSA::TIER);
function a_req($http, $method, $uri, $auth, array $body = []) {
    try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.251.' . random_int(1, 254) . '.' . random_int(1, 254)];
    if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
    $req = $method === 'GET' ? Request::create('http://localhost' . $uri, 'GET', $body, [], [], $server) : Request::create('http://localhost' . $uri, $method, [], [], [], $server, json_encode($body));
    $res = $http->handle($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 160)];
}
$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = []; foreach (['v2_order', 'v2_payment', 'v2_plan', 'v2_server', 'v2_server_group', 'v2_settings', 'v2_coupon'] as $t) if (Schema::hasTable($t)) $before[$t] = $rows($t);
$realAccess = function () {
    $out = [];
    foreach (User::where('email', 'not like', 'rollback-test-%')->orderBy('id')->get() as $u) {
        $v = array_map('intval', array_column(ServerService::getAvailableServers($u), 'id')); sort($v);
        $out[$u->id] = [$u->plan_id, $u->group_id, $u->assigned_server_id, $v];
    }
    return $out;
};
$access0 = $realAccess();
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));

DB::beginTransaction();
try {
    $n = 0;
    $group = function () use (&$n) { $g = ['name' => 'rollback-test-pa1 group ' . (++$n)]; foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $g[$c] = time(); return DB::table('v2_server_group')->insertGetId($g); };
    $server = function ($gids, $type = 'ikev2', $show = 1, $parent = null, $name = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-pa1 ' . ($name ?? Str::random(6)), 'group_ids' => array_map('intval', (array) $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier, $aud = 'public', $dev = 2, $name = null) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-pa1 ' . ($name ?? ($tier ?? 'untiered') . " $aud $dev"), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => $dev,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => $aud, 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $user = function (?Plan $p, $assigned = null, array $x = []) {
        $u = new User(); $u->email = 'rollback-test-pa1-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['plan_id' => $p?->id, 'group_id' => $p?->group_id, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => $p ? time() + 10 * 86400 : null, 'banned' => 0, 'is_partner' => 0, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $auth = fn (User $u) => (new AuthService($u))->generateAuthData()['auth_data'];
    $open = function (User $u, Plan $p, $type) {
        $o = new Order(['user_id' => $u->id, 'plan_id' => $p->id, 'type' => $type, 'period' => 'days_30', 'trade_no' => 'RBPA' . Str::random(20), 'total_amount' => 0]);
        $o->status = Order::STATUS_PROCESSING; $o->save();
        try { (new OrderService(Order::find($o->id)))->open(); $ok = true; } catch (\Throwable $e) { $ok = false; }
        return [$ok, Order::find($o->id)];
    };
    $state = fn (User $u) => User::find($u->id)->only(['plan_id', 'group_id', 'expired_at', 'assigned_server_id', 'transfer_enable']);
    $vis = function (User $u) { $v = array_map('intval', array_column(ServerService::getAvailableServers(User::find($u->id)), 'id')); sort($v); return $v; };
    $nodes = function (User $u, array $serverIds) { $o = []; foreach ($serverIds as $id) if (ServerService::getAvailableUsers((array) Server::find($id)->group_ids, $id)->pluck('id')->map(fn ($v) => (int) $v)->contains((int) $u->id)) $o[] = $id; sort($o); return $o; };
    $sorted = function (array $a) { sort($a); return $a; };

    // ---------------- LEGACY ----------------
    $gL = $group(); $gO = $group();
    $A = $server($gL); $B = $server($gL); $C = $server($gL); $hid = $server($gL, 'ikev2', 0); $child = $server($gL, 'ikev2', 1, $A); $hy = $server($gL, 'hysteria'); $other = $server($gO);
    $all = [$A, $B, $C, $hid, $child, $hy, $other];
    $legacy = $plan($gL, 'legacy'); $basicL = $plan($gL, 'basic');
    $u1 = $user(null); [$ok] = $open($u1, $legacy, Order::TYPE_NEW_PURCHASE); $s1 = $state($u1);
    a_check('L1 LEGACY purchase assigns exactly one eligible IKEv2 server (shown root ikev2 of the group; never hidden / child / hysteria / other group)',
        $ok && in_array((int) $s1['assigned_server_id'], [$A, $B, $C], true), json_encode($s1));
    a_check('L2 LEGACY getAvailableServers returns only the assigned server', $vis($u1) === [(int) $s1['assigned_server_id']], json_encode($vis($u1)));
    a_check('L3 LEGACY only the assigned node receives the user (credential / user list)', $nodes($u1, $all) === [(int) $s1['assigned_server_id']], json_encode($nodes($u1, $all)));
    $g0 = $group(); $server($g0, 'ikev2', 0); $server($g0, 'hysteria'); $legacy0 = $plan($g0, 'legacy');
    $u2 = $user(null); $b2 = $state($u2); [$ok2, $o2] = $open($u2, $legacy0, Order::TYPE_NEW_PURCHASE);
    $code = null; try { BSA::resolve($legacy0, null, null); } catch (ApiException $e) { $code = $e->getCode(); }
    a_check('L4 LEGACY with no eligible node -> 422, open fails, user unchanged, order not completed', !$ok2 && $code === 422 && $state($u2) == $b2 && (int) $o2->status === Order::STATUS_PROCESSING, "code=$code");
    $keep = (int) $s1['assigned_server_id']; [$okR] = $open($u1, $legacy, Order::TYPE_RENEWAL);
    a_check('L5 LEGACY renewal keeps a still-valid assignment', $okR && (int) $state($u1)['assigned_server_id'] === $keep);
    $gI = $group(); $I1 = $server($gI); $I2 = $server($gI); $legacyI = $plan($gI, 'legacy'); $u5 = $user($legacyI, $I1);
    Server::where('id', $I1)->update(['show' => 0]);
    [$okI] = $open($u5, $legacyI, Order::TYPE_RENEWAL);
    a_check('L6 LEGACY invalid assignment (server hidden) is replaced by an eligible one on renewal', $okI && (int) $state($u5)['assigned_server_id'] === $I2, json_encode($state($u5)));
    $u6 = $user($legacy, $A); [$ok6] = $open($u6, $basicL, Order::TYPE_UPGRADE);
    a_check('L7 LEGACY -> BASIC clears assigned_server_id', $ok6 && $state($u6)['assigned_server_id'] === null && (int) $state($u6)['plan_id'] === (int) $basicL->id, json_encode($state($u6)));

    // ---------------- BASIC ----------------
    $gB = $group(); $gX = $group();
    $b1 = $server($gB); $b2s = $server($gB); $b3 = $server($gB); $bHid = $server($gB, 'ikev2', 0); $bChild = $server($gB, 'ikev2', 1, $b1);
    $x1 = $server($gX); $xHy = $server($gX, 'hysteria');
    $allB = [$b1, $b2s, $b3, $bHid, $bChild, $x1, $xHy];
    $basic = $plan($gB, 'basic');
    $ub = $user(null); [$okB] = $open($ub, $basic, Order::TYPE_NEW_PURCHASE); $sb = $state($ub);
    a_check('B1 BASIC purchase succeeds without assignment', $okB && (int) $sb['plan_id'] === (int) $basic->id && (int) $sb['group_id'] === $gB, json_encode($sb));
    a_check('B2 BASIC assigned_server_id stays NULL', $sb['assigned_server_id'] === null);
    a_check('B3 BASIC sees every shown IKEv2 server of its group (hidden excluded)', $vis($ub) === $sorted([$b1, $b2s, $b3, $bChild]), json_encode($vis($ub)));
    a_check('B4 BASIC never sees servers of another group (incl. Hysteria2 elsewhere)', !array_intersect($vis($ub), [$x1, $xHy]));
    a_check('B5 BASIC every node of its group receives the user; other groups do not', $nodes($ub, $allB) === $sorted([$b1, $b2s, $b3, $bHid, $bChild]), json_encode($nodes($ub, $allB)));

    // ---------------- PRO / MASTER ----------------
    $gP = $group(); $gM = $group();
    $pIk = $server([$gP, $gM]); $pHy = $server([$gP, $gM], 'hysteria'); $mOver = $server([$gM], 'ikev2', 1, null, 'overseas'); $mAdv = $server([$gM], 'hysteria', 1, null, 'advanced');
    $pro = $plan($gP, 'pro'); $master = $plan($gM, 'master');
    [$okBP] = $open($ub, $pro, Order::TYPE_UPGRADE);
    a_check('P0 BASIC -> PRO keeps plain group behaviour (no assignment)', $okBP && $state($ub)['assigned_server_id'] === null);
    $up = $user(null); [$okP] = $open($up, $pro, Order::TYPE_NEW_PURCHASE);
    a_check('P1 PRO sees IKEv2 + Hysteria2 of its group, not MASTER-only nodes', $okP && $vis($up) === $sorted([$pIk, $pHy]) && $state($up)['assigned_server_id'] === null, json_encode($vis($up)));
    a_check('P2 PRO nodes (IKEv2 and Hysteria2) both receive the user', $nodes($up, [$pIk, $pHy, $mOver, $mAdv]) === $sorted([$pIk, $pHy]));
    $um = $user(null); [$okM] = $open($um, $master, Order::TYPE_NEW_PURCHASE);
    a_check('M1 MASTER sees IKEv2 + Hysteria2 + its advanced/overseas nodes', $okM && $vis($um) === $sorted([$pIk, $pHy, $mOver, $mAdv]) && $state($um)['assigned_server_id'] === null, json_encode($vis($um)));

    // ---------------- PARTNER (audience only) ----------------
    $pubB = $plan($gB, 'basic', 'public', 3, 'pub basic 3'); $parB = $plan($gB, 'basic', 'partner', 3, 'par basic 3');
    $pubL = $plan($gL, 'legacy', 'public', 3, 'pub legacy 3'); $parL = $plan($gL, 'legacy', 'partner', 3, 'par legacy 3');
    $normal = $user(null); $partner = $user(null, null, ['is_partner' => 1]);
    [$sN, $jN] = a_req($http, 'GET', $R['userPlans'], $auth($normal)); $idsN = array_map('intval', array_column($jN['data'] ?? [], 'id'));
    [$sP, $jP] = a_req($http, 'GET', $R['userPlans'], $auth($partner)); $idsP = array_map('intval', array_column($jP['data'] ?? [], 'id'));
    a_check('R1 audience filtering unchanged: normal user sees the public row, partner sees the partner counterpart instead (basic and legacy)',
        $sN === 200 && $sP === 200 && in_array($pubB->id, $idsN) && !in_array($parB->id, $idsN) && in_array($parB->id, $idsP) && !in_array($pubB->id, $idsP)
        && in_array($pubL->id, $idsN) && !in_array($parL->id, $idsN) && in_array($parL->id, $idsP) && !in_array($pubL->id, $idsP), "normal=$sN partner=$sP");
    $bn = $user(null); $bp = $user(null, null, ['is_partner' => 1]); $open($bn, $pubB, Order::TYPE_NEW_PURCHASE); $open($bp, $parB, Order::TYPE_NEW_PURCHASE);
    a_check('R2 partner BASIC has the same entitlement as its public counterpart (group, devices, visible servers, no assignment)',
        (int) $state($bp)['group_id'] === (int) $state($bn)['group_id'] && $vis($bp) === $vis($bn) && $state($bp)['assigned_server_id'] === null
        && (int) User::find($bp->id)->device_limit === (int) User::find($bn->id)->device_limit);
    $ln = $user(null); $lp = $user(null, null, ['is_partner' => 1]); $open($ln, $pubL, Order::TYPE_NEW_PURCHASE); $open($lp, $parL, Order::TYPE_NEW_PURCHASE);
    a_check('R3 partner LEGACY gets the same single-server entitlement as its public counterpart',
        in_array((int) $state($lp)['assigned_server_id'], [$A, $B, $C], true) && in_array((int) $state($ln)['assigned_server_id'], [$A, $B, $C], true) && count($vis($lp)) === 1);

    // ---------------- VIP ----------------
    $vip = $plan($gB, 'vip', 'admin_only', 2, 'vip');
    [$sU, $jU] = a_req($http, 'GET', $R['userPlans'], $auth($normal)); [$sG, $jG] = a_req($http, 'GET', $R['guestPlans'], null);
    a_check('V1 VIP (admin_only) absent from user and guest store', $sU === 200 && $sG === 200 && !in_array($vip->id, array_map('intval', array_column($jU['data'] ?? [], 'id')))
        && !in_array($vip->id, array_map('intval', array_column($jG['data'] ?? [], 'id'))), "user=$sU guest=$sG");
    $admin = $user(null, null, ['is_admin' => 1]); $target = $user(null);
    [$sA, , $rawA] = a_req($http, 'POST', $R['assign'], $auth($admin), ['plan_id' => $vip->id, 'email' => $target->email, 'period' => 'days_30', 'total_amount' => 0]);
    $oA = Order::where('user_id', $target->id)->first();
    a_check('V2 admin order/assign of the VIP plan still works (pending order created)', $sA === 200 && $oA && (int) $oA->plan_id === (int) $vip->id, "http=$sA $rawA");

    // ---------------- VALIDATION ----------------
    $body = fn ($name, $tier) => ['name' => "rollback-test-pa1 val $name", 'transfer_enable' => 100, 'group_id' => $gB, 'device_limit' => 2, 'capacity_limit' => null,
        'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'show' => 1, 'sell' => 1, 'renew' => 1, 'audience' => 'public', 'service_tier' => $tier];
    $adminAuth = $auth($admin); $got = [];
    foreach (['legacy', 'basic', 'pro', 'master', 'vip'] as $t) { [$s] = a_req($http, 'POST', $R['planSave'], $adminAuth, $body($t, $t)); $row = Plan::where('name', "rollback-test-pa1 val $t")->first(); $got[$t] = [$s, $row?->service_tier]; }
    a_check('X1 legacy / basic / pro / master / vip accepted and stored as sent', array_map(fn ($v) => $v[0], $got) === array_fill_keys(['legacy', 'basic', 'pro', 'master', 'vip'], 200)
        && array_map(fn ($v) => $v[1], $got) === ['legacy' => 'legacy', 'basic' => 'basic', 'pro' => 'pro', 'master' => 'master', 'vip' => 'vip'], json_encode($got));
    $rej = [];
    foreach (['elite', 'abc'] as $t) { [$s] = a_req($http, 'POST', $R['planSave'], $adminAuth, $body($t, $t)); $rej[$t] = [$s, Plan::where('name', "rollback-test-pa1 val $t")->exists()]; }
    a_check('X2 elite / abc rejected with 422, nothing stored', $rej === ['elite' => [422, false], 'abc' => [422, false]], json_encode($rej));
} catch (\Throwable $e) {
    a_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
a_check('S1 existing real users (plan 1 / plan 2 etc.) keep identical access (plan, group, assignment, visible servers)', $realAccess() === $access0);
a_check('S2 no real user has an assigned server', DB::table('v2_user')->where('email', 'not like', 'rollback-test-%')->whereNotNull('assigned_server_id')->count() === 0);
a_check('INV-1 orders / payments / plans / servers / groups / settings / coupons identical after rollback', $after === $before);
a_check('INV-2 no temp rows left', User::where('email', 'like', 'rollback-test-pa1%')->count() === 0 && Plan::where('name', 'like', 'rollback-test-pa1%')->count() === 0
    && DB::table('v2_server_group')->where('name', 'like', 'rollback-test-pa1%')->count() === 0 && Server::where('name', 'like', 'rollback-test-pa1%')->count() === 0);
a_check('INV-3 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
