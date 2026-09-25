<?php
// ManduVPN PP-C2 regression: BASIC auto-assignment through OrderService::open (purchase / renewal / upgrade / reset)
// and admin user/update + generate. Everything is created in temporary groups inside ONE rolled-back transaction;
// real users / servers / plans are never in scope. No node contact, no payment, no Toss.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, Server, User};
use App\Services\{AuthService, BasicServerAssignmentService as BSA, OrderService, ServerService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function c2_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($action) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action)); return $r ? '/' . $r->uri() : null; };
$R = ['userUpdate' => $route('Admin\UserController@update'), 'generate' => $route('Admin\UserController@generate')];
c2_check('setup: admin routes + service class', $R['userUpdate'] && $R['generate'] && class_exists(BSA::class), json_encode($R));
$biz = ['id', 'plan_id', 'group_id', 'is_partner', 'expired_at', 'banned', 'device_limit', 'speed_limit', 'transfer_enable', 'u', 'd', 'assigned_server_id'];
$realUsers = fn () => hash('sha256', json_encode(DB::table('v2_user')->where('email', 'not like', 'rollback-test-%')->orderBy('id')->get($biz)->map(fn ($r) => array_values((array) $r))->all()));
$usersBefore = $realUsers();
$legacyPlans = DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$serversBefore = DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$ordersBefore = DB::table('v2_order')->count();

DB::beginTransaction();
try {
    $n = 0;
    $group = function () use (&$n) { $g = ['name' => 'rollback-test-c2 group ' . (++$n)]; foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $g[$c] = time(); return DB::table('v2_server_group')->insertGetId($g); };
    $server = function ($gids, $type = 'ikev2', $show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-c2 ' . Str::random(6), 'group_ids' => array_map('intval', (array) $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier, $name = null) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-c2 ' . ($name ?? ($tier ?? 'legacy')), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $user = function (?Plan $p, $assigned = null, array $x = []) {
        $u = new User(); $u->email = 'rollback-test-c2-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['plan_id' => $p?->id, 'group_id' => $p?->group_id, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => $p ? time() + 10 * 86400 : null, 'banned' => 0, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $load = function (Plan $basic, $serverId, $count, array $x = []) use ($user) { for ($i = 0; $i < $count; $i++) $user($basic, $serverId, $x); };
    // open an order exactly like the payment / admin-paid / balance paths do (OrderHandleJob -> OrderService::open)
    $open = function (User $u, Plan $p, $type, $period = 'days_30') {
        $o = new Order(['user_id' => $u->id, 'plan_id' => $p->id, 'type' => $type, 'period' => $period, 'trade_no' => 'RBC2' . Str::random(20), 'total_amount' => 0]);
        $o->status = Order::STATUS_PROCESSING; $o->save();
        try { (new OrderService(Order::find($o->id)))->open(); $ok = true; } catch (\Throwable $e) { $ok = false; }
        return [$ok, Order::find($o->id)];
    };
    $state = fn (User $u) => User::find($u->id)->only(['plan_id', 'group_id', 'expired_at', 'assigned_server_id', 'transfer_enable']);
    $world = function () use ($group, $server, $plan) {
        $g = $group(); $g2 = $group();
        $w = ['g' => $g, 'A' => $server($g), 'B' => $server($g), 'C' => $server($g)];
        $w['hidden'] = $server($g, 'ikev2', 0); $w['child'] = $server($g, 'ikev2', 1, $w['A']); $w['other'] = $server($g2);
        $w['basic'] = $plan($g, 'legacy'); $w['pro'] = $plan($g, 'pro'); $w['master'] = $plan($g, 'master'); $w['vip'] = $plan($g, 'vip'); $w['legacy'] = $plan($g, null);
        return $w;
    };
    $in = fn ($v, array $set) => $v !== null && in_array((int) $v, $set, true);

    // ---- purchase ----
    $w = $world(); $abc = [$w['A'], $w['B'], $w['C']];
    $u = $user(null); [$ok] = $open($u, $w['basic'], Order::TYPE_NEW_PURCHASE); $s = $state($u);
    $vis = array_map('intval', array_column(ServerService::getAvailableServers(User::find($u->id)), 'id'));
    $nodes = array_values(array_filter([$w['A'], $w['B'], $w['C']], fn ($id) => ServerService::getAvailableUsers([$w['g']], $id)->pluck('id')->contains($u->id)));
    c2_check('C2-T1 new BASIC purchase -> exactly one eligible server assigned with plan/group/expiry; user list and node lists = that server only',
        $ok && $s['plan_id'] === $w['basic']->id && (int) $s['group_id'] === $w['g'] && $s['expired_at'] > time() && $in($s['assigned_server_id'], $abc) && $vis === [(int) $s['assigned_server_id']] && $nodes === [(int) $s['assigned_server_id']],
        'assigned=' . var_export($s['assigned_server_id'], true) . ' visible=' . json_encode($vis) . ' nodes=' . json_encode($nodes));
    $seen = []; for ($i = 0; $i < 30; $i++) { $x = $user(null); $open($x, $w['basic'], Order::TYPE_NEW_PURCHASE); $seen[] = (int) User::find($x->id)->assigned_server_id; }
    c2_check('C2-T2 eligibility: never hidden, child (parent_id), other-group or hysteria servers (30 assignments)', !array_diff($seen, $abc), 'seen=' . json_encode(array_count_values($seen)) . ' eligible=' . json_encode(BSA::eligibleServerIds($w['g'])));
    $hy = null; try { $hy = $server($w['g'], 'hysteria'); } catch (\Throwable $e) {}
    c2_check('C2-T2b a hysteria server in the BASIC group is not eligible', $hy === null || !in_array($hy, BSA::eligibleServerIds($w['g']), true), $hy === null ? 'hysteria row could not be created (skipped)' : "hysteria=$hy");

    // ---- load policy ----
    $w = $world(); $load($w['basic'], $w['A'], 2); $load($w['basic'], $w['B'], 1); $load($w['basic'], $w['C'], 1);
    $u = $user(null); $open($u, $w['basic'], Order::TYPE_NEW_PURCHASE);
    c2_check('C2-T3 least-loaded server chosen (A=2,B=1,C=1 -> B or C)', $in(User::find($u->id)->assigned_server_id, [$w['B'], $w['C']]), 'got ' . User::find($u->id)->assigned_server_id);
    $w = $world(); $load($w['basic'], $w['A'], 2); $load($w['basic'], $w['B'], 2); $got = [];
    for ($i = 0; $i < 5; $i++) { $x = $user(null); $open($x, $w['basic'], Order::TYPE_NEW_PURCHASE); $got[] = (int) User::find($x->id)->assigned_server_id; $load($w['basic'], $w['A'], 1); $load($w['basic'], $w['B'], 1); }
    c2_check('C2-T4 a unique least-loaded server is always chosen (C while A,B stay ahead)', array_unique($got) === [$w['C']], json_encode($got));
    $w = $world(); $got = [];
    for ($i = 0; $i < 30; $i++) { $got[] = BSA::resolve($w['basic'], null, null); }
    c2_check('C2-T5 ties are broken randomly and only among the tied servers (A=B=C=0, 30 draws)', !array_diff($got, [$w['A'], $w['B'], $w['C']]) && count(array_unique($got)) >= 2, json_encode(array_count_values($got)));
    $w = $world();
    $load($w['basic'], $w['A'], 3, ['expired_at' => time() - 60]); $load($w['basic'], $w['A'], 3, ['banned' => 1]); $load($w['pro'], $w['A'], 3);
    $load($w['basic'], $w['B'], 1); $load($w['basic'], $w['C'], 1);
    c2_check('C2-T6 load counts only ACTIVE BASIC users (expired / banned / non-BASIC on A ignored -> A chosen)', BSA::resolve($w['basic'], null, null) === $w['A'], json_encode(BSA::activeBasicCounts([$w['A'], $w['B'], $w['C']])));

    // ---- renewal ----
    $w = $world(); $u = $user($w['basic'], $w['A']); $load($w['basic'], $w['A'], 5);
    [$ok] = $open($u, $w['basic'], Order::TYPE_RENEWAL);
    c2_check('C2-T7 BASIC -> BASIC renewal keeps a still-eligible server even when it is not the least loaded', $ok && (int) User::find($u->id)->assigned_server_id === $w['A']);
    $w = $world(); $u = $user($w['basic'], $w['A']); Server::where('id', $w['A'])->update(['show' => 0]);
    [$ok] = $open($u, $w['basic'], Order::TYPE_RENEWAL);
    c2_check('C2-T8 renewal reassigns when the assigned server was hidden', $ok && $in(User::find($u->id)->assigned_server_id, [$w['B'], $w['C']]), 'got ' . User::find($u->id)->assigned_server_id);
    $w = $world(); $u = $user($w['basic'], $w['A']); Server::find($w['A'])->forceFill(['group_ids' => [(int) ($w['g'] + 1000)]])->save();
    [$ok] = $open($u, $w['basic'], Order::TYPE_RENEWAL);
    c2_check('C2-T9 renewal reassigns when the assigned server left the plan group', $ok && $in(User::find($u->id)->assigned_server_id, [$w['B'], $w['C']]), 'got ' . User::find($u->id)->assigned_server_id);
    $w = $world(); $u = $user($w['basic'], $w['A']); Server::where('id', $w['A'])->delete();
    [$ok] = $open($u, $w['basic'], Order::TYPE_RENEWAL);
    c2_check('C2-T10 renewal reassigns when the assigned server was deleted', $ok && $in(User::find($u->id)->assigned_server_id, [$w['B'], $w['C']]), 'got ' . User::find($u->id)->assigned_server_id);

    // ---- tier changes ----
    $w = $world(); $load($w['basic'], $w['A'], 3); $u = $user($w['pro'], $w['A']);
    [$ok] = $open($u, $w['basic'], Order::TYPE_UPGRADE);
    c2_check('C2-T11 PRO -> BASIC gets a NEW assignment (a stale id from the previous tier is not reused)', $ok && $in(User::find($u->id)->assigned_server_id, [$w['B'], $w['C']]), 'got ' . User::find($u->id)->assigned_server_id);
    $w = $world(); $u = $user($w['legacy']); [$ok] = $open($u, $w['basic'], Order::TYPE_UPGRADE);
    c2_check('C2-T12 legacy (tier NULL) -> BASIC gets a new assignment', $ok && $in(User::find($u->id)->assigned_server_id, [$w['A'], $w['B'], $w['C']]));
    foreach (['pro' => 'C2-T13', 'master' => 'C2-T14', 'vip' => 'C2-T15', 'legacy' => 'C2-T16'] as $to => $t) {
        $w = $world(); $u = $user($w['basic'], $w['A']); [$ok] = $open($u, $w[$to], Order::TYPE_UPGRADE); $s = $state($u);
        c2_check("$t BASIC -> " . strtoupper($to) . ' clears assigned_server_id (plan/group switched in the same save)', $ok && $s['assigned_server_id'] === null && $s['plan_id'] === $w[$to]->id);
    }

    // ---- fail closed ----
    $g3 = $group(); $server($g3, 'ikev2', 0); $nb = $plan($g3, 'legacy', 'basic-no-node'); $pro3 = $plan($g3, 'pro');
    $u = $user($pro3); $before = $state($u); [$ok, $o] = $open($u, $nb, Order::TYPE_UPGRADE);
    c2_check('C2-T17 BASIC with no eligible node -> open fails; plan/group/expiry/assigned and order status unchanged', !$ok && $state($u) == $before && (int) $o->status === Order::STATUS_PROCESSING, json_encode($state($u)));
    $w = $world(); $u = $user($w['basic'], $w['A']); Server::whereIn('id', [$w['A'], $w['B'], $w['C']])->update(['show' => 0]); $before = $state($u);
    [$ok, $o] = $open($u, $w['basic'], Order::TYPE_RENEWAL);
    c2_check('C2-T18 BASIC renewal with no eligible node left -> fails closed, nothing partially changed', !$ok && $state($u) == $before && (int) $o->status === Order::STATUS_PROCESSING);
    $w = $world(); $u = $user($w['basic'], $w['A']); $load($w['basic'], $w['A'], 4);
    [$ok] = $open($u, $w['basic'], Order::TYPE_RESET_TRAFFIC, Plan::PERIOD_RESET_TRAFFIC);
    c2_check('C2-T19 traffic-reset order keeps the BASIC assignment', $ok && (int) User::find($u->id)->assigned_server_id === $w['A']);

    // ---- admin user/update + generate (real HTTP kernel) ----
    $admin = $user(null, null, ['is_admin' => 1]); $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $req = function ($uri, array $body) use ($http, $adminAuth) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $res = $http->handle(Request::create($uri, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $adminAuth], json_encode($body)));
        return [$res->getStatusCode(), substr($res->getContent(), 0, 160)];
    };
    $base = fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'is_admin' => 0, 'is_staff' => 0];
    $w = $world(); $u = $user($w['legacy']);
    [$st, $raw] = $req($R['userUpdate'], $base($u) + ['plan_id' => $w['basic']->id]); $s = $state($u);
    c2_check('C2-T20 admin user/update legacy -> BASIC assigns a server in the same save as plan/group', $st === 200 && $s['plan_id'] === $w['basic']->id && (int) $s['group_id'] === $w['g'] && $in($s['assigned_server_id'], [$w['A'], $w['B'], $w['C']]), "http=$st $raw");
    [$st, $raw] = $req($R['userUpdate'], $base($u) + ['plan_id' => $w['pro']->id]);
    c2_check('C2-T21 admin user/update BASIC -> PRO clears assigned_server_id', $st === 200 && $state($u)['assigned_server_id'] === null && $state($u)['plan_id'] === $w['pro']->id, "http=$st $raw");
    $w = $world(); $u = $user($w['basic'], $w['A']); $load($w['basic'], $w['A'], 5);
    [$st, $raw] = $req($R['userUpdate'], $base($u) + ['plan_id' => $w['basic']->id, 'remarks' => 'rollback-test unrelated']);
    c2_check('C2-T22 admin re-save with the same BASIC plan (admin UI payload) keeps the assignment', $st === 200 && (int) $state($u)['assigned_server_id'] === $w['A'], "http=$st $raw");
    $u = $user($pro3); $before = $state($u);
    [$st, $raw] = $req($R['userUpdate'], $base($u) + ['plan_id' => $nb->id]);
    c2_check('C2-T23 admin user/update to BASIC with no eligible node -> 422, user unchanged', $st === 422 && $state($u) == $before, "http=$st $raw");
    $w = $world(); $u = $user($w['basic'], $w['A']);
    [$st, $raw] = $req($R['userUpdate'], $base($u) + ['plan_id' => null]);
    c2_check('C2-T24 admin user/update plan_id=null clears assigned_server_id', $st === 200 && $state($u)['assigned_server_id'] === null && $state($u)['plan_id'] === null, "http=$st $raw");
    $cnt = User::where('email', 'like', 'rollback-test-c2gen%')->count();
    [$st, $raw] = $req($R['generate'], ['email_prefix' => 'rollback-test-c2gen' . Str::lower(Str::random(6)), 'email_suffix' => 'example.invalid', 'plan_id' => $w['basic']->id]);
    [$st2] = $req($R['generate'], ['email_prefix' => 'rollback-test-c2gen' . Str::lower(Str::random(6)), 'email_suffix' => 'example.invalid', 'plan_id' => $w['pro']->id]);
    c2_check('C2-T25 admin generate with a BASIC plan -> 422, no account; non-BASIC generate unaffected (200)', $st === 422 && $st2 === 200 && User::where('email', 'like', 'rollback-test-c2gen%')->count() === $cnt + 1, "http=$st/$st2 $raw");

    // ---- tier source ----
    $gx = $group(); $sx = $server($gx);
    $nameBasicTierPro = $plan($gx, 'pro', 'BASIC named but pro'); $namePremiumTierBasic = $plan($gx, 'legacy', 'PREMIUM named but basic');
    c2_check('C2-T26 tier comes only from plan.service_tier (a "BASIC"-named pro plan -> NULL; a "PREMIUM"-named basic plan -> assigned)',
        BSA::resolve($nameBasicTierPro, null, null) === null && BSA::resolve($namePremiumTierBasic, null, null) === $sx);
} catch (\Throwable $e) {
    c2_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-c2%')->count() + Plan::where('name', 'like', 'rollback-test-c2%')->count() + Order::where('trade_no', 'like', 'RBC2%')->count()
      + DB::table('v2_server')->where('name', 'like', 'rollback-test-c2%')->count() + DB::table('v2_server_group')->where('name', 'like', 'rollback-test-c2%')->count();
c2_check('rollback: no temp users / plans / orders / servers / groups left', $left === 0, "left=$left");
c2_check('protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
c2_check('real users unchanged (business fields, assigned_server_id all NULL)', $realUsers() === $usersBefore && DB::table('v2_user')->whereNotNull('assigned_server_id')->count() === 0);
c2_check('plan rows 1-11 and server rows unchanged; order count unchanged', DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $legacyPlans
    && DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $serversBefore && DB::table('v2_order')->count() === $ordersBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
