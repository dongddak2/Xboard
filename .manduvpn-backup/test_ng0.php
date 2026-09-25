<?php
// ManduVPN NG-0 regression: v2_server.group_ids canonical JSON integer array (reads + write normalisation).
// Everything in ONE rolled-back transaction; temp rows only; no Toss; live rows are never written.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, Server, ServerGroup, User};
use App\Services\{AuthService, BasicServerAdminService as BAS, BasicServerAssignmentService as BSA, ServerService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
$fails = 0;
function g_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n -- $d\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['save' => $route('ManageController@save'), 'groupDrop' => $route('GroupController@drop')];
g_check('setup: routes found', !in_array(null, $R, true), json_encode($R));
$rows = fn ($t) => hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$before = []; foreach (['v2_server', 'v2_server_group', 'v2_user', 'v2_plan', 'v2_order'] as $t) $before[$t] = $rows($t);
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));

DB::beginTransaction();
try {
    $n = 0;
    $group = function () use (&$n) { $g = ['name' => 'rollback-test-ng0 group ' . (++$n)]; foreach (['created_at', 'updated_at'] as $c) if (Schema::hasColumn('v2_server_group', $c)) $g[$c] = time(); return (int) DB::table('v2_server_group')->insertGetId($g); };
    $server = function (array $gids, $type = 'ikev2', $show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-ng0 ' . Str::random(6), 'group_ids' => array_map('intval', $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-ng0 ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $user = function (?Plan $p, $assigned = null, array $x = []) {
        $u = new User(); $u->email = 'rollback-test-ng0-' . Str::lower(Str::random(10)) . '@example.invalid';
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['plan_id' => $p?->id, 'group_id' => $p?->group_id, 'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => $p ? time() + 10 * 86400 : null, 'banned' => 0, 'assigned_server_id' => $assigned], $x))->save();
        return User::find($u->id);
    };
    $g1 = $group(); $g2 = $group(); $g3 = $group();
    $S = $server([$g1, $g2]); $T = $server([$g1]); $Hd = $server([$g1], 'ikev2', 0); $P = $server([$g1], 'ikev2', 1, $S);
    $Y = null; try { $Y = $server([$g1], 'hysteria'); } catch (\Throwable $e) {}
    $temp = array_values(array_filter([$S, $T, $Hd, $P, $Y]));
    $vis = function (User $u) use ($temp) { $ids = array_map('intval', array_column(ServerService::getAvailableServers(User::find($u->id)), 'id')); $ids = array_values(array_intersect($ids, $temp)); sort($ids); return $ids; };
    $sorted = function (array $a) { $a = array_values(array_filter($a)); sort($a); return $a; };
    // independent expectation: decode the JSON in PHP (no whereJsonContains involved)
    $withGroup = function (int $g, bool $showOnly, ?array $only = null) { $out = []; foreach (DB::table('v2_server')->get(['id', 'group_ids', 'show']) as $r) {
        if ($only !== null && !in_array((int) $r->id, $only, true)) continue;
        $d = json_decode((string) $r->group_ids, true); if (is_array($d) && in_array($g, $d, true) && (!$showOnly || (int) $r->show === 1)) $out[] = (int) $r->id; } sort($out); return $out; };

    $raw = DB::table('v2_server')->where('id', $S)->value('group_ids'); $dec = json_decode($raw, true);
    $u1 = $user($plan($g1, null)); $u2 = $user($plan($g2, null));
    g_check('NG-1 group_ids stored as JSON integers [g1,g2]; group-1 and group-2 users both match S', $dec === [$g1, $g2] && is_int($dec[0])
        && in_array($S, $vis($u1), true) && $vis($u2) === [$S], "raw=$raw vis1=" . json_encode($vis($u1)) . ' vis2=' . json_encode($vis($u2)));
    $u3 = $user($plan($g3, 'pro'));
    g_check('NG-2 unrelated group matches nothing (user list, raw query, eligibleServerIds)', $vis($u3) === []
        && !in_array($S, Server::whereJsonContains('group_ids', $g3)->pluck('id')->map(fn ($v) => (int) $v)->all(), true) && BSA::eligibleServerIds($g3) === []);
    $basic = $plan($g1, 'legacy'); $pro = $plan($g1, 'pro');
    $ub = $user($basic, $S);
    g_check('NG-3 BASIC assigned user receives only the assigned server', $vis($ub) === [$S], json_encode($vis($ub)));
    $ubn = $user($basic, null);
    g_check('NG-4 BASIC with assigned_server_id NULL fails closed (empty)', $vis($ubn) === [] && ServerService::getAvailableServers(User::find($ubn->id)) === []);
    $up = $user($pro);
    g_check('NG-5 PRO / non-BASIC: every shown server of the group (same as an independent JSON decode)', $vis($up) === $withGroup($g1, true, $temp) && $vis($u1) === $withGroup($g1, true, $temp),
        'pro=' . json_encode($vis($up)) . ' expect=' . json_encode($withGroup($g1, true, $temp)));
    g_check('NG-6 show=0 excluded (user list and BASIC eligibility)', !in_array($Hd, $vis($up), true) && !in_array($Hd, BSA::eligibleServerIds($g1), true));
    g_check('NG-7 child node unchanged: listed for PRO users (no parent filter, as before), never BASIC-eligible', in_array($P, $vis($up), true) && !in_array($P, BSA::eligibleServerIds($g1), true));
    g_check('NG-8 BasicServerAssignmentService::eligibleServerIds finds integer group_ids (ikev2 + shown + root only)', BSA::eligibleServerIds($g1) === $sorted([$S, $T]) && BSA::eligibleServerIds($g2) === [$S],
        json_encode(BSA::eligibleServerIds($g1)));
    $d = BAS::describe(User::find($ub->id)); $ids = array_column($d['eligible_servers'], 'id');
    BAS::assign(User::find($ub->id), $T); $afterAssign = (int) User::find($ub->id)->assigned_server_id;
    $bad = (function () use ($ub, $Hd) { try { BAS::assign(User::find($ub->id), $Hd); return 'no-exception'; } catch (\App\Exceptions\ApiException $e) { return (string) $e->getCode(); } })();
    $dp = BAS::describe(User::find($up->id));
    g_check('NG-9 BasicServerAdminService: VALID, eligible [S,T], manual assign to T ok, hidden node 422, PRO NOT_APPLICABLE', $d['state'] === 'VALID' && $ids === $sorted([$S, $T])
        && $afterAssign === $T && $bad === '422' && $dp['state'] === 'NOT_APPLICABLE' && (int) User::find($ub->id)->assigned_server_id === $T, "state={$d['state']} eligible=" . json_encode($ids) . " hidden=$bad");
    $res = BSA::resolve($basic, null, null);
    g_check('NG-9b PP-C2 resolve() picks an eligible integer-group server for a new BASIC activation', in_array($res, [$S, $T], true), 'resolved=' . var_export($res, true));

    // ---------- write side through the real admin save route ----------
    $admin = $user(null, null, ['is_admin' => 1]); $auth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $call = function ($uri, array $body) use ($http, $auth) { try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $res = $http->handle(Request::create($uri, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $auth], json_encode($body)));
        return [$res->getStatusCode(), substr((string) $res->getContent(), 0, 160)]; };
    $body = fn ($gids, $name = null) => ['type' => 'shadowsocks', 'name' => $name ?? 'rollback-test-ng0 save ' . Str::random(6), 'host' => '192.0.2.9', 'port' => '8388', 'server_port' => 8388,
        'rate' => 1, 'show' => 0, 'group_ids' => $gids, 'protocol_settings' => ['cipher' => 'aes-128-gcm']];
    $save = function (array $b) use ($call, $R) { [$s, $raw] = $call($R['save'], $b); $row = DB::table('v2_server')->where('name', $b['name'])->first(); return [$s, $row, $raw]; };
    [$s, $row, $raw] = $save($body([$g1, $g2]));
    g_check('NG-10 node save group_ids [g1,g2] -> stored JSON integer array [g1,g2]', $s === 200 && $row && json_decode($row->group_ids, true) === [$g1, $g2], "http=$s stored=" . ($row->group_ids ?? '-') . " $raw");
    [$s, $row] = $save($body([(string) $g1, (string) $g2]));
    g_check('NG-11 node save ["g1","g2"] -> normalised to [g1,g2] integers', $s === 200 && $row && json_decode($row->group_ids, true) === [$g1, $g2], "http=$s stored=" . ($row->group_ids ?? '-'));
    [$s, $row] = $save($body([$g1, (string) $g1, $g2]));
    g_check('NG-12 node save [g1,"g1",g2] -> de-duplicated [g1,g2]', $s === 200 && $row && json_decode($row->group_ids, true) === [$g1, $g2], "http=$s stored=" . ($row->group_ids ?? '-'));
    $badSets = [['abc'], [0], [-1], ['0'], ['1.5'], [1.5], [true], [null], [''], [[1]], [' 1'], ['01'], [$g1, 'abc'], [$g1, 0]];
    $st = []; $created = 0;
    foreach ($badSets as $i => $b) { [$s, $row] = $save($body($b)); $st[] = $s; if ($row) $created++;
        echo 'NG-13 case ' . ($i + 1) . ' input=' . json_encode($b) . " expected=422 actual=$s" . ($row ? ' ROW_CREATED' : '') . "\n"; }
    g_check('NG-13 malformed / non-positive ids rejected (14 cases, incl. mixed valid+invalid) -> 422, no row created, nothing coerced to 0', array_values(array_unique($st)) === [422] && $created === 0, implode(',', $st));
    $name = 'rollback-test-ng0 upd ' . Str::random(6); [$s0, $r0] = $save($body([$g1], $name));
    [$s] = $call($R['save'], array_merge($body([(string) $g2], $name), ['id' => (int) $r0->id])); $r1 = DB::table('v2_server')->where('id', $r0->id)->first();
    $strip = fn ($r) => array_diff_key((array) $r, array_flip(['group_ids', 'updated_at']));
    g_check('NG-14 update path: group_ids ["g2"] stored [g2]; every other column unchanged', $s0 === 200 && $s === 200 && json_decode($r1->group_ids, true) === [$g2] && $strip($r0) == $strip($r1), "http=$s0/$s");
    $cnt = ServerGroup::find($g1)->servers()->count(); $expectCnt = count($withGroup($g1, false));
    [$sd] = $call($R['groupDrop'], ['id' => (string) $g1]);
    g_check('NG-15 ServerGroup::servers() counts integer members; group drop refuses an in-use group even with a string id', $cnt === $expectCnt && $cnt >= 4 && ServerGroup::find($g1) !== null,
        "servers=$cnt expect=$expectCnt drop_http=$sd");
} catch (\Throwable $e) {
    g_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
g_check('NG-16 no real server / group / user / plan / order changed (all temp rows rolled back)', $after === $before && Server::where('name', 'like', 'rollback-test-ng0%')->count() === 0);
g_check('NG-17 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
