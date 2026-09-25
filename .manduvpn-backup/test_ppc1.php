<?php
// ManduVPN PP-C1 regression: BASIC single-server entitlement in BOTH directions (user server list + node credential
// list, incl. the real UniProxy user/alivelist routes). Everything lives in ONE rolled-back transaction inside a
// temporary server group, so real users/servers are never in scope. Cache keys touched for temp ids are removed.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, Server, User};
use App\Services\{ServerService, UserOnlineService};
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function c1_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
function c1_note($n, $d) { echo "NOTE $n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($action) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $action)); return $r ? '/' . $r->uri() : null; };
$R = ['user' => $route('UniProxyController@user'), 'alive' => $route('UniProxyController@alivelist')];
$token = (string) admin_setting('server_token', '');
c1_check('setup: UniProxy routes, server token, assigned_server_id column', $R['user'] && $R['alive'] && $token !== '' && Schema::hasColumn('v2_user', 'assigned_server_id'), json_encode($R));
$biz = ['id', 'plan_id', 'group_id', 'is_partner', 'expired_at', 'banned', 'device_limit', 'speed_limit', 'transfer_enable', 'u', 'd', 'assigned_server_id'];
$realUsers = fn () => hash('sha256', json_encode(DB::table('v2_user')->where('email', 'not like', 'rollback-test-%')->orderBy('id')->get($biz)->map(fn ($r) => array_values((array) $r))->all()));
$usersBefore = $realUsers();
$legacyPlans = DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$serversBefore = DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$prefix = (new ReflectionClassConstant(UserOnlineService::class, 'CACHE_PREFIX'))->getValue();
$cacheKeys = [];

DB::beginTransaction();
try {
    $grp = ['name' => 'rollback-test-c1 group'];
    foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $grp[$c] = time();
    $gid = DB::table('v2_server_group')->insertGetId($grp);
    $mkServer = function ($name) use ($gid, &$cacheKeys) {
        $s = new Server(); $s->forceFill(['type' => 'ikev2', 'name' => "rollback-test-c1 $name", 'group_ids' => [(int) $gid], 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => 1, 'sort' => 9999])->save();
        $cacheKeys[] = CacheKey::get('SERVER_IKEV2_LAST_CHECK_AT', $s->id);
        return Server::find($s->id);
    };
    [$A, $B, $C] = [$mkServer('A'), $mkServer('B'), $mkServer('C')];
    $all = [$A->id, $B->id, $C->id]; sort($all);
    $mkPlan = function ($tier) use ($gid) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-c1 ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $P = ['legacy' => $mkPlan(null), 'basic' => $mkPlan('legacy'), 'pro' => $mkPlan('pro'), 'master' => $mkPlan('master'), 'vip' => $mkPlan('vip')];
    $mkUser = function ($planId, $assigned, array $x = []) use ($gid) {
        $u = new User(); $u->email = 'rollback-test-c1-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['group_id' => $gid, 'plan_id' => $planId, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0, 'expired_at' => time() + 30 * 86400,
            'banned' => 0, 'device_limit' => 2, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $vis = function (User $u) use ($all) { $ids = array_map('intval', array_column(ServerService::getAvailableServers(User::find($u->id)), 'id')); $ids = array_values(array_intersect($ids, $all)); sort($ids); return $ids; };
    $has = fn ($nodeId, User $u) => in_array((int) $u->id, ServerService::getAvailableUsers([$gid], $nodeId)->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
    $allNodes = fn (User $u) => $has($A->id, $u) && $has($B->id, $u) && $has($C->id, $u);
    $noNode = fn (User $u) => !$has($A->id, $u) && !$has($B->id, $u) && !$has($C->id, $u);
    $viaRoute = function ($path, Server $s) use ($http, $token) {
        $res = $http->handle(Request::create($path, 'GET', ['token' => $token, 'node_id' => $s->id, 'node_type' => 'ikev2'], [], [], ['HTTP_ACCEPT' => 'application/json']));
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: []];
    };
    $routeUsers = function (Server $s) use ($viaRoute, $R) { [$st, $j] = $viaRoute($R['user'], $s); return [$st, collect($j['users'] ?? [])->pluck('id')->map(fn ($v) => (int) $v)->all()]; };

    $u1 = $mkUser($P['legacy']->id, null);
    c1_check('C1-T1 legacy tier NULL + assigned NULL -> every group server visible, every node receives', $vis($u1) === $all && $allNodes($u1), json_encode($vis($u1)));
    $u2 = $mkUser($P['legacy']->id, $A->id);
    c1_check('C1-T2 legacy tier NULL + assigned set -> still plain group behaviour (no accidental restriction)', $vis($u2) === $all && $allNodes($u2), json_encode($vis($u2)));
    $u3 = $mkUser($P['basic']->id, $A->id);
    c1_check('C1-T3 basic + assigned A -> user-facing list is exactly [A]', $vis($u3) === [$A->id], json_encode($vis($u3)));
    [$sA, $idsA] = $routeUsers($A); [$sB, $idsB] = $routeUsers($B);
    c1_check('C1-T4 basic + assigned A -> node A receives the credential (service + real UniProxy/user route)', $has($A->id, $u3) && $sA === 200 && in_array((int) $u3->id, $idsA, true), "route=$sA");
    c1_check('C1-T5 basic + assigned A -> nodes B and C (same group) do NOT receive it (service + route for B)', !$has($B->id, $u3) && !$has($C->id, $u3) && $sB === 200 && !in_array((int) $u3->id, $idsB, true), "route=$sB");
    $u6 = $mkUser($P['basic']->id, null);
    c1_check('C1-T6 basic + assigned NULL -> user-facing list empty (fail closed)', $vis($u6) === [], json_encode($vis($u6)));
    c1_check('C1-T7 basic + assigned NULL -> no node receives the credential', $noNode($u6) && !in_array((int) $u6->id, $idsA, true) && !in_array((int) $u6->id, $idsB, true));
    foreach (['pro' => 'C1-T8', 'master' => 'C1-T9', 'vip' => 'C1-T10'] as $tier => $t) {
        $u = $mkUser($P[$tier]->id, null);
        c1_check("$t $tier + assigned NULL -> group behaviour unchanged (all servers visible, all nodes receive)", $vis($u) === $all && $allNodes($u), json_encode($vis($u)));
    }
    $u11 = $mkUser($P['basic']->id, $A->id, ['expired_at' => time() - 60]);
    c1_check('C1-T11 expired BASIC excluded from its assigned node', !$has($A->id, $u11));
    $u12 = $mkUser($P['basic']->id, $A->id, ['banned' => 1]);
    c1_check('C1-T12 banned BASIC excluded from its assigned node', !$has($A->id, $u12));
    $u13 = $mkUser($P['basic']->id, $A->id, ['u' => 60 * 1073741824, 'd' => 40 * 1073741824]);
    c1_check('C1-T13 traffic-exhausted BASIC excluded from its assigned node', !$has($A->id, $u13));

    $u14 = $mkUser($P['basic']->id, $A->id); $u14p = $mkUser($P['pro']->id, null);
    foreach ([$u14, $u14p] as $u) { Cache::put($prefix . $u->id, ['alive_ip' => 2, 'updated_at' => time()], 300); $cacheKeys[] = $prefix . $u->id; }
    [$aSt, $aJ] = $viaRoute($R['alive'], $A); [$bSt, $bJ] = $viaRoute($R['alive'], $B);
    $aliveA = array_map('intval', array_keys((array) ($aJ['alive'] ?? []))); $aliveB = array_map('intval', array_keys((array) ($bJ['alive'] ?? [])));
    c1_check('C1-T14 alivelist uses the same node entitlement (A: basic+pro, B: pro only)', $aSt === 200 && $bSt === 200 && in_array((int) $u14->id, $aliveA, true) && in_array((int) $u14p->id, $aliveA, true)
        && !in_array((int) $u14->id, $aliveB, true) && in_array((int) $u14p->id, $aliveB, true), "A=" . json_encode($aliveA) . " B=" . json_encode($aliveB));

    $u15a = $mkUser(null, $A->id); $u15b = $mkUser(99999999, $A->id);
    c1_check('C1-T15 no plan / missing plan (even with assigned set) -> legacy-safe group behaviour', $vis($u15a) === $all && $allNodes($u15a) && $vis($u15b) === $all && $allNodes($u15b));
    c1_note('legacy callers (no node id)', 'getAvailableUsers([group]) without a node id still returns BASIC users (Tidalab callers unchanged): ' . (ServerService::getAvailableUsers([$gid])->pluck('id')->contains($u3->id) ? 'yes' : 'no'));

    for ($i = 0; $i < 60; $i++) $mkUser($P['pro']->id, null);
    DB::flushQueryLog(); DB::enableQueryLog();
    $n = ServerService::getAvailableUsers([$gid], $A->id)->count();
    $q = DB::getQueryLog(); DB::disableQueryLog();
    c1_check('C1-PERF node credential list = ONE query (plan tier via subquery), independent of user count', count($q) === 1 && str_contains($q[0]['query'] ?? '', 'service_tier'), count($q) . " quer(ies) for $n users: " . substr($q[0]['query'] ?? '', 0, 400));
    DB::flushQueryLog(); DB::enableQueryLog(); $vis($u3); $qs = count(DB::getQueryLog()); DB::disableQueryLog();
    c1_note('C1-PERF user server list', "$qs DB queries per call (includes the test's own User::find)");
} catch (\Throwable $e) {
    c1_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
    foreach ($cacheKeys as $k) Cache::forget($k);
}
$left = User::where('email', 'like', 'rollback-test-c1-%')->count() + Plan::where('name', 'like', 'rollback-test-c1%')->count()
      + DB::table('v2_server')->where('name', 'like', 'rollback-test-c1%')->count() + DB::table('v2_server_group')->where('name', 'like', 'rollback-test-c1%')->count();
c1_check('rollback: no temp users / plans / servers / groups left', $left === 0, "left=$left");
c1_check('C1-T16 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
c1_check('C1-T17 existing users unchanged (business fields + assigned_server_id all NULL)', $realUsers() === $usersBefore && DB::table('v2_user')->whereNotNull('assigned_server_id')->count() === 0);
c1_check('C1-T18 plan rows 1-11 byte-identical; server rows unchanged', DB::table('v2_plan')->whereIn('id', range(1, 11))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $legacyPlans
    && DB::table('v2_server')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $serversBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
