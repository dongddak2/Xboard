<?php
// ManduVPN PP-C3 regression: admin BASIC server view / manual / auto reassignment through the real HTTP kernel.
// Temporary groups, servers, plans and users in ONE rolled-back transaction; real rows never in scope.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, Server, User};
use App\Services\{AuthService, BasicServerAdminService as BAS, BasicServerAssignmentService as BSA, OrderService, ServerService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function c3_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($action) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action)); return $r ? '/' . $r->uri() : null; };
$R = ['fetch' => $route('BasicServerController@fetch'), 'assign' => $route('BasicServerController@assign'), 'reassign' => $route('BasicServerController@reassign')];
c3_check('setup: 3 admin routes registered', !in_array(null, $R, true), json_encode($R));
$biz = ['id', 'plan_id', 'group_id', 'is_partner', 'expired_at', 'banned', 'device_limit', 'speed_limit', 'transfer_enable', 'u', 'd', 'assigned_server_id'];
$realUsers = fn () => hash('sha256', json_encode(DB::table('v2_user')->where('email', 'not like', 'rollback-test-%')->orderBy('id')->get($biz)->map(fn ($r) => array_values((array) $r))->all()));
$usersBefore = $realUsers();
$legacyPlans = DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$serversBefore = DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));

DB::beginTransaction();
try {
    $n = 0;
    $group = function () use (&$n) { $g = ['name' => 'rollback-test-c3 group ' . (++$n)]; foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $g[$c] = time(); return DB::table('v2_server_group')->insertGetId($g); };
    $server = function ($gids, $type = 'ikev2', $show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-c3 ' . Str::random(6), 'group_ids' => array_map('intval', (array) $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-c3 ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $user = function (?Plan $p, $assigned = null, array $x = []) {
        $u = new User(); $u->email = 'rollback-test-c3-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['plan_id' => $p?->id, 'group_id' => $p?->group_id, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => $p ? time() + 10 * 86400 : null, 'banned' => 0, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $load = function (Plan $p, $sid, $count, array $x = []) use ($user) { for ($i = 0; $i < $count; $i++) $user($p, $sid, $x); };
    $g = $group(); $g2 = $group();
    $A = $server($g); $B = $server($g); $C = $server($g); $H = $server($g, 'ikev2', 0); $P = $server($g, 'ikev2', 1, $A); $X = $server($g2);
    $Y = null; try { $Y = $server($g, 'hysteria'); } catch (\Throwable $e) {}
    $basic = $plan($g, 'legacy'); $pro = $plan($g, 'pro'); $master = $plan($g, 'master'); $vip = $plan($g, 'vip');
    $admin = $user(null, null, ['is_admin' => 1]); $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $normal = $user(null); $normalAuth = (new AuthService($normal))->generateAuthData()['auth_data'];
    $call = function ($method, $uri, array $body, $auth = null) use ($http, &$adminAuth) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $auth ?? $adminAuth];
        $req = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, 'POST', [], [], [], $server, json_encode($body));
        $res = $http->handle($req);
        return [$res->getStatusCode(), json_decode($res->getContent(), true)['data'] ?? null, substr($res->getContent(), 0, 160)];
    };
    $state = fn (User $u) => $call('GET', $R['fetch'], ['id' => $u->id]);
    $snap = fn (User $u) => User::find($u->id)->only(['plan_id', 'group_id', 'expired_at', 'assigned_server_id']);

    [$st, $d] = $state($user($basic, $A));
    c3_check('C3-T1 BASIC with an eligible assigned server -> VALID (server id/name/type/host/show reported)', $st === 200 && $d['state'] === 'VALID' && $d['assigned_server']['id'] === $A && $d['assigned_server']['type'] === 'ikev2' && $d['assigned_server']['show'] === true && $d['service_tier'] === 'legacy', "http=$st");
    [$st, $d] = $state($user($basic, null));
    c3_check('C3-T2 BASIC with NULL assignment -> MISSING', $st === 200 && $d['state'] === 'MISSING' && $d['assigned_server'] === null);
    [$st, $d] = $state($user($basic, $H));
    c3_check('C3-T3 BASIC assigned to a hidden server -> INVALID', $st === 200 && $d['state'] === 'INVALID' && $d['assigned_server']['show'] === false);
    $gone = $server($g); $ug = $user($basic, $gone); Server::where('id', $gone)->delete(); [$st, $d] = $state($ug);
    c3_check('C3-T4 BASIC assigned to a deleted server -> INVALID (assigned_server null)', $st === 200 && $d['state'] === 'INVALID' && $d['assigned_server'] === null && $d['assigned_server_id'] === $gone);
    [$st, $d] = $state($user($basic, $X));
    c3_check('C3-T5 BASIC assigned to a server outside the plan group -> INVALID', $st === 200 && $d['state'] === 'INVALID');
    $ok6 = true; foreach ([$pro, $master, $vip] as $pp) { [$st, $d] = $state($user($pp, $A)); $ok6 = $ok6 && $st === 200 && $d['state'] === 'NOT_APPLICABLE' && $d['eligible_servers'] === []; }
    [$st, $d] = $state($user(null)); $ok6 = $ok6 && $d['state'] === 'NOT_APPLICABLE';
    c3_check('C3-T6 PRO / MASTER / VIP / no plan -> NOT_APPLICABLE, no selector data', $ok6);
    [$st, $d] = $state($user($basic, $A)); $ids = array_column($d['eligible_servers'], 'id');
    c3_check('C3-T7 eligible list = IKEv2 + shown + root + in plan group only (excludes hidden, child, other group, hysteria)', $ids === [$A, $B, $C] && $ids === BSA::eligibleServerIds($g) && ($Y === null || !in_array($Y, $ids, true)),
        json_encode($ids) . ' hysteria=' . var_export($Y, true) . ' child=' . $P . ' hidden=' . $H . ' other=' . $X);
    $g8 = $group(); $A8 = $server($g8); $B8 = $server($g8); $basic8 = $plan($g8, 'legacy'); $pro8 = $plan($g8, 'pro');
    $load($basic8, $A8, 2); $load($basic8, $A8, 1, ['expired_at' => time() - 60]); $load($basic8, $A8, 1, ['banned' => 1]); $load($pro8, $A8, 1); $load($basic8, $B8, 1);
    [$st, $d] = $state($user($basic8, $B8)); $cnt = array_column($d['eligible_servers'], 'active_basic_users', 'id');
    c3_check('C3-T8 per-node counts use the PP-C2 active-BASIC definition (A8: 2 active; expired/banned/pro ignored; B8: 1+self)', $cnt === BSA::activeBasicCounts([$A8, $B8]) && $cnt[$A8] === 2 && $cnt[$B8] === 2, json_encode($cnt));

    $u9 = $user($basic, $A); $b9 = $snap($u9);
    [$st, $d, $raw] = $call('POST', $R['assign'], ['id' => $u9->id, 'server_id' => $B]); $a9 = $snap($u9);
    c3_check('C3-T9 manual reassignment to an eligible node -> 200, VALID; plan/group/expiry untouched', $st === 200 && $d['state'] === 'VALID' && $a9['assigned_server_id'] === $B && $a9['plan_id'] === $b9['plan_id'] && $a9['group_id'] === $b9['group_id'] && $a9['expired_at'] === $b9['expired_at'], "http=$st $raw");
    $bad = ['C3-T10 hidden' => $H, 'C3-T11 hysteria' => $Y, 'C3-T12 child' => $P, 'C3-T13 outside group' => $X, 'C3-T13b nonexistent' => 999999999];
    foreach ($bad as $label => $sid) {
        if ($sid === null) { c3_check("$label -> skipped (hysteria row could not be created)", true); continue; }
        $u = $user($basic, $A); $before = $snap($u); [$st, , $raw] = $call('POST', $R['assign'], ['id' => $u->id, 'server_id' => $sid]);
        c3_check("$label node -> 422, user unchanged", $st === 422 && $snap($u) == $before, "http=$st $raw");
    }
    $ok14 = true; $d14 = [];
    foreach (['pro' => $pro, 'master' => $master, 'vip' => $vip] as $k => $pp) {
        $u = $user($pp, null); $before = $snap($u);
        [$s1] = $call('POST', $R['assign'], ['id' => $u->id, 'server_id' => $A]); [$s2] = $call('POST', $R['reassign'], ['id' => $u->id]);
        $ok14 = $ok14 && $s1 === 422 && $s2 === 422 && $snap($u) == $before; $d14[] = "$k=$s1/$s2";
    }
    c3_check('C3-T14 manual and auto BASIC assignment rejected (422) for PRO / MASTER / VIP, users unchanged', $ok14, implode(' ', $d14));

    $g15 = $group(); $A15 = $server($g15); $B15 = $server($g15); $C15 = $server($g15); $basic15 = $plan($g15, 'legacy');
    $load($basic15, $A15, 3); $load($basic15, $C15, 3); $u15 = $user($basic15, $A15);
    [$st, $d, $raw] = $call('POST', $R['reassign'], ['id' => $u15->id]);
    c3_check('C3-T15 auto reassign = PP-C2 least-loaded choice even when the current server is valid (A=4 incl. self, B=0, C=3 -> B)', $st === 200 && $snap($u15)['assigned_server_id'] === $B15 && $d['state'] === 'VALID', "http=$st $raw");
    $g16 = $group(); $A16 = $server($g16); $basic16 = $plan($g16, 'legacy'); $u16 = $user($basic16, $A16); Server::where('id', $A16)->update(['show' => 0]); $before = $snap($u16);
    [$st, , $raw] = $call('POST', $R['reassign'], ['id' => $u16->id]);
    c3_check('C3-T16 auto reassign with no eligible node -> 422, assignment unchanged', $st === 422 && $snap($u16) == $before, "http=$st $raw");
    $g17 = $group(); $A17 = $server($g17); $B17 = $server($g17); $basic17 = $plan($g17, 'legacy'); $u17 = $user($basic17, $A17); $load($basic17, $A17, 4);
    $o = new Order(['user_id' => $u17->id, 'plan_id' => $basic17->id, 'type' => Order::TYPE_RENEWAL, 'period' => 'days_30', 'trade_no' => 'RBC3' . Str::random(20), 'total_amount' => 0]);
    $o->status = Order::STATUS_PROCESSING; $o->save(); (new OrderService(Order::find($o->id)))->open();
    c3_check('C3-T17 normal BASIC renewal still keeps a valid assignment (PP-C2 semantics unchanged)', $snap($u17)['assigned_server_id'] === $A17);
    $ids18A = ServerService::getAvailableUsers([$g], $A)->pluck('id')->all(); $ids18B = ServerService::getAvailableUsers([$g], $B)->pluck('id')->all();
    c3_check('C3-T18 after manual reassignment the PP-C1 credential filter follows it (node B has the user, node A not)', in_array($u9->id, $ids18B) && !in_array($u9->id, $ids18A)
        && array_column(ServerService::getAvailableServers(User::find($u9->id)), 'id') == [$B]);
    [$st] = $call('GET', $R['fetch'], ['id' => $u9->id], $normalAuth); [$st2] = $call('POST', $R['assign'], ['id' => $u9->id, 'server_id' => $A], $normalAuth);
    c3_check('C3-auth non-admin cannot read or change BASIC assignment (403), unchanged', $st === 403 && $st2 === 403 && $snap($u9)['assigned_server_id'] === $B, "http=$st/$st2");
    $u21 = $user($basic, $A); $stale = User::find($u21->id); User::where('id', $u21->id)->update(['assigned_server_id' => $C]);
    $code = null; try { BAS::assign($stale, $B); } catch (\App\Exceptions\ApiException $e) { $code = $e->getCode(); }
    c3_check('C3-race a stale admin view never overwrites a concurrent change (409, value kept)', $code === 409 && $snap($u21)['assigned_server_id'] === $C, "code=" . var_export($code, true));
} catch (\Throwable $e) {
    c3_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$left = User::where('email', 'like', 'rollback-test-c3%')->count() + Plan::where('name', 'like', 'rollback-test-c3%')->count() + Order::where('trade_no', 'like', 'RBC3%')->count()
      + DB::table('v2_server')->where('name', 'like', 'rollback-test-c3%')->count() + DB::table('v2_server_group')->where('name', 'like', 'rollback-test-c3%')->count();
c3_check('rollback: no temp rows left', $left === 0, "left=$left");
c3_check('C3-T20 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
c3_check('real users unchanged (business fields, assigned_server_id all NULL)', $realUsers() === $usersBefore && DB::table('v2_user')->whereNotNull('assigned_server_id')->count() === 0);
c3_check('plan rows 1-11 and server rows unchanged', DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $legacyPlans
    && DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $serversBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
