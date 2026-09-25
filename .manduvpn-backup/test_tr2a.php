<?php
// ManduVPN TR-2A regression: read-only user trial status endpoint. ONE rolled-back transaction; fixed clock via Carbon.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Plan, User};
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
const GIB = 1073741824;
$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$rt = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_ends_with($r->getActionName(), 'TrialStatusController@fetch'))->values();
$URI = count($rt) === 1 ? '/' . $rt[0]->uri() : null;
t_check('R1 route: exactly one, GET /api/v1/user/manduvpn/trial, user middleware', $URI === '/api/v1/user/manduvpn/trial' && $rt[0]->methods() === ['GET', 'HEAD'] && in_array('user', $rt[0]->gatherMiddleware(), true), (string) $URI);
$src = (string) file_get_contents('/www/app/Http/Controllers/V1/User/TrialStatusController.php');
t_check('R2 controller source has no write call', !preg_match('/->(update|insert\w*|save|delete|increment|decrement|upsert|forceFill|fill|touch)\s*\(|DB::(statement|unprepared|insert|update|delete)|Cache::|Log::/', $src));
$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = []; foreach (['v2_order', 'v2_payment', 'v2_plan', 'v2_server', 'v2_server_group', 'v2_settings', 'v2_coupon', 'v2_invite_code', 'v2_commission_log'] as $t) if (Schema::hasTable($t)) $before[$t] = $rows($t);
$vpn0 = DB::table('v2_vpn_credential')->count(); $granted0 = DB::table('v2_user')->whereNotNull('trial_granted_at')->count();
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$T = time();
Carbon::setTestNow(Carbon::createFromTimestamp($T));

DB::beginTransaction();
try {
    // every synthetic user carries unique S8 sentinels in its sensitive columns (rolled back; values are never printed)
    $sent = [];
    $mkUser = function (array $x = []) use (&$sent) {
        $r = Str::lower(Str::random(16));
        $u = new User(); $u->email = "rollback-test-tr2a-s8-secret-email-$r@example.invalid";
        $u->password = "S8_SECRET_PASSWORD_$r"; $u->uuid = "S8_SECRET_UUID_$r"; $u->token = "S8_SECRET_TOKEN_$r"; $u->balance = 0;
        $u->forceFill(array_merge(['transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => 0, 'expired_at' => 0], $x))->save();
        $sent[(int) $u->id] = (array) DB::table('v2_user')->where('id', $u->id)->first(['email', 'uuid', 'token', 'password']);
        return User::find($u->id);
    };
    $trial = fn (array $x = []) => $mkUser(array_merge(['plan_id' => null, 'trial_granted_at' => $T - 3600, 'expired_at' => $T + 82800, 'transfer_enable' => 10 * GIB,
        'u' => 0, 'd' => 0, 'device_limit' => 1, 'speed_limit' => null], $x));
    // fixture plan: same field set as the frozen TR-1b fixture (rolled back)
    $gid = (int) DB::table('v2_server_group')->insertGetId(['name' => 'rollback-test-tr2a group', 'created_at' => time(), 'updated_at' => time()]);
    $p = new Plan(); $p->forceFill(['name' => 'rollback-test-tr2a pro', 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
        'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
        'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => 'pro'])->save();
    $planId = (int) $p->id;
    $all = [];
    // $exp = expected sorted data keys from the approved contract (S8-E); null = rejected request, data must be null
    $get = function (string $label, ?User $u, ?array $exp, ?string $auth = null) use ($http, $URI, &$all) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)];
        if ($u) $auth = (new AuthService($u))->generateAuthData()['auth_data'];
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $res = $http->handle(Request::create('http://localhost' . $URI, 'GET', [], [], [], $server));
        $raw = (string) $res->getContent(); $j = json_decode($raw, true) ?: [];
        $all[] = [$label, $u, $j, $exp];
        return [$res->getStatusCode(), $j['data'] ?? null, $j];
    };
    $same = function ($a, $b) { if (!is_array($a) || !is_array($b)) return false; ksort($a); ksort($b); return $a === $b; };
    $tr = fn ($state, $u, $d, $total, $expires = null, array $x = []) => array_merge(['state' => $state, 'trial_used' => true, 'granted_at' => $T - 3600,
        'expires_at' => $expires ?? $T + 82800, 'transfer_enable' => $total, 'used_bytes' => $u + $d, 'remaining_bytes' => max($total - $u - $d, 0), 'device_limit' => 1, 'speed_limit' => null], $x);
    $case = function ($name, User $u, array $expect) use ($get, $same) {
        $ek = array_keys($expect); sort($ek, SORT_STRING);
        [$st, $d] = $get(strtok($name, ' '), $u, $ek);
        t_check($name, $st === 200 && $same($d, $expect), "http=$st got=" . json_encode($d));
    };

    $case('S1 plan-less user never granted -> none', $mkUser(['plan_id' => null, 'trial_granted_at' => null, 'expired_at' => $T + 86400, 'transfer_enable' => 5 * GIB]), ['state' => 'none', 'trial_used' => false]);
    $case('S2 active trial with exact numbers', $trial(['u' => GIB, 'd' => 2 * GIB]), $tr('active', GIB, 2 * GIB, 10 * GIB));
    $case('S2b active trial carries device/speed snapshot as integers', $trial(['device_limit' => 3, 'speed_limit' => 50, 'u' => 7, 'd' => 5]), $tr('active', 7, 5, 10 * GIB, null, ['device_limit' => 3, 'speed_limit' => 50]));
    $case('S3 quota exhausted before expiry -> exhausted, remaining 0', $trial(['u' => 6 * GIB, 'd' => 4 * GIB + 1]), $tr('exhausted', 6 * GIB, 4 * GIB + 1, 10 * GIB));
    $case('S4 expired with remaining traffic -> expired, remaining reported', $trial(['expired_at' => $T - 1, 'u' => GIB]), $tr('expired', GIB, 0, 10 * GIB, $T - 1));
    $case('S5 paid user with historical trial -> paid, no trial numbers', $trial(['plan_id' => $planId, 'expired_at' => $T + 30 * 86400, 'transfer_enable' => 100 * GIB]), ['state' => 'paid', 'trial_used' => true]);
    $case('S5b paid user without trial history -> paid, trial_used false', $mkUser(['plan_id' => $planId, 'expired_at' => $T + 86400, 'transfer_enable' => 100 * GIB]), ['state' => 'paid', 'trial_used' => false]);
    $case('S6 paid user whose plan itself expired -> paid', $trial(['plan_id' => $planId, 'expired_at' => $T - 86400]), ['state' => 'paid', 'trial_used' => true]);
    $case('S6b paid user with NULL expiry (lifetime) -> paid', $mkUser(['plan_id' => $planId, 'expired_at' => null]), ['state' => 'paid', 'trial_used' => false]);
    [$s7a, $d7a] = $get('S7a-no-token', null, null); [$s7b, $d7b] = $get('S7b-invalid-token', null, null, 'Bearer ' . Str::random(40));
    t_check('S7 unauthenticated / invalid token rejected by the existing user middleware (401/403, no data)', in_array($s7a, [401, 403], true) && in_array($s7b, [401, 403], true) && $d7a === null && $d7b === null, "no_auth=$s7a bad_token=$s7b");
    $case('S11 used == transfer_enable -> exhausted', $trial(['u' => 4 * GIB, 'd' => 6 * GIB]), $tr('exhausted', 4 * GIB, 6 * GIB, 10 * GIB));
    $case('S12 used > transfer_enable -> exhausted, remaining 0', $trial(['u' => 9 * GIB, 'd' => 9 * GIB]), $tr('exhausted', 9 * GIB, 9 * GIB, 10 * GIB));
    $case('S13 expired_at == now -> expired', $trial(['expired_at' => $T]), $tr('expired', 0, 0, 10 * GIB, $T));
    $case('S13b expired_at == now + 1 -> active', $trial(['expired_at' => $T + 1]), $tr('active', 0, 0, 10 * GIB, $T + 1));
    $case('S14 transfer_enable 0 on an unexpired granted plan-less trial -> exhausted', $trial(['transfer_enable' => 0]), $tr('exhausted', 0, 0, 0));
    $case('M1 malformed: granted plan-less row with NULL expired_at -> expired (never active)', $trial(['expired_at' => null]), $tr('expired', 0, 0, 10 * GIB, null, ['expires_at' => null]));
    $case('M2 malformed: negative u -> exhausted, remaining 0 (never active)', $trial(['u' => -5 * GIB, 'd' => GIB]), $tr('exhausted', 0, GIB, 10 * GIB, null, ['used_bytes' => GIB, 'remaining_bytes' => 0]));
    $case('M3 malformed: plan_id 0 -> paid (contract: plan_id NOT NULL)', $trial(['plan_id' => 0]), ['state' => 'paid', 'trial_used' => true]);
    $case('M4 malformed: plan_id of a deleted plan -> paid (contract: plan_id NOT NULL)', $trial(['plan_id' => 999999999]), ['state' => 'paid', 'trial_used' => true]);

    // ---- S8 whitelist: every subcondition printed independently. Info lines are indented and never contain the
    //      word used as a failure marker; only the t_check lines (S8-A..S8-E + aggregate) start with PASS/FAIL.
    $C_PN = ['state', 'trial_used'];
    $C_TRIAL = ['device_limit', 'expires_at', 'granted_at', 'remaining_bytes', 'speed_limit', 'state', 'transfer_enable', 'trial_used', 'used_bytes'];
    $TOP = ['data', 'error', 'message', 'status'];
    $sorted = function (array $a) { sort($a, SORT_STRING); return $a; };
    // S8-E approved contract: the harness arrays must themselves be sorted, and every scenario must expect exactly one of them
    echo '  S8-E expected paid/none keys = ' . json_encode($C_PN) . "\n";
    echo '  S8-E expected trial keys     = ' . json_encode($C_TRIAL) . "\n";
    echo '  S8-E expected top-level keys = ' . json_encode($TOP) . "\n";
    $eOk = $C_PN === $sorted($C_PN) && $C_TRIAL === $sorted($C_TRIAL) && $TOP === $sorted($TOP);
    foreach ($all as [$label, , , $exp]) if ($exp !== null && $exp !== $C_PN && $exp !== $C_TRIAL) { $eOk = false; echo "  S8-E scenario=$label expects keys outside the approved contract: " . json_encode($exp) . "\n"; }
    t_check('S8-E approved contract arrays sorted; every scenario expects exactly one of them', $eOk);
    // S8-A
    $aOk = count($all) === 20;
    echo '  S8-A actual_count=' . count($all) . " expected_count=20\n";
    t_check('S8-A response count', $aOk, 'actual=' . count($all) . ' expected=20');
    // S8-B exact key sets per response (expected = the scenario's approved contract, not derived from the response)
    $bOk = true; $n = 0;
    foreach ($all as [$label, , $j, $exp]) {
        $n++;
        $top = $sorted(array_map('strval', array_keys($j)));
        $d = $j['data'] ?? null;
        $ak = is_array($d) ? $sorted(array_map('strval', array_keys($d))) : null;
        $match = $ak === $exp; $topOk = $top === $TOP;
        $state = is_array($d) ? (string) ($d['state'] ?? '(no state key)') : '(data null)';
        echo "  S8-B #$n scenario=$label state=$state actual=" . json_encode($ak) . ' expected=' . json_encode($exp) . ' exact_match=' . (int) $match . ' top=' . json_encode($top) . ' top_ok=' . (int) $topOk . "\n";
        if (!$match || !$topOk) $bOk = false;
    }
    t_check('S8-B exact data key sets + top-level {data,error,message,status} for every response', $bOk);
    // S8-C forbidden structural keys at any depth of the parsed JSON
    $forbidden = '/^(email|uuid|token|auth_data|subscribe_url|password|password_salt|balance|group_id|plan_id)$|^(commission|invite|telegram|order|payment|credential)/i';
    $walk = function ($node, bool $withStrings) use (&$walk) { $out = []; if (is_array($node)) { foreach ($node as $k => $v) { $out[] = (string) $k; array_push($out, ...$walk($v, $withStrings)); } } elseif ($withStrings && is_string($node)) { $out[] = $node; } return $out; };
    $cHits = 0;
    foreach ($all as [$label, , $j]) foreach ($walk($j, false) as $k) if (preg_match($forbidden, $k)) { $cHits++; echo "  S8-C scenario=$label forbidden_key=$k\n"; }
    if (!$cHits) echo "  S8-C NONE\n";
    t_check('S8-C no forbidden structural key at any depth', $cHits === 0, "hits=$cHits");
    // S8-D sentinel leak scan over parsed keys + string values; only the sentinel TYPE and a boolean are printed
    $types = ['email', 'uuid', 'token', 'password'];
    $byType = array_fill_keys($types, []);
    foreach ($sent as $s) foreach ($types as $ty) if (is_string($s[$ty] ?? null) && strlen($s[$ty]) >= 16) $byType[$ty][] = $s[$ty];
    $sentOk = count($sent) >= 18; foreach ($types as $ty) if (count($byType[$ty]) !== count($sent)) $sentOk = false;
    echo '  S8-D sentinel_users=' . count($sent) . ' per_type=' . json_encode(array_map('count', $byType)) . ' setup_ok=' . (int) $sentOk . "\n";
    $dLeaks = 0;
    foreach ($all as [$label, , $j]) {
        $strs = $walk($j, true);
        foreach ($types as $ty) {
            $leak = false;
            foreach ($byType[$ty] as $sv) { foreach ($strs as $s) if (str_contains($s, $sv)) { $leak = true; break 2; } }
            echo "  S8-D scenario=$label sentinel=$ty leaked=" . ($leak ? 'true' : 'false') . "\n";
            if ($leak) $dLeaks++;
        }
    }
    t_check('S8-D no sentinel email/uuid/token/password in any parsed response key or string value', $dLeaks === 0 && $sentOk, "leaks=$dLeaks setup_ok=" . (int) $sentOk);
    t_check('S8 response whitelist (aggregate of S8-A..S8-E)', $aOk && $bOk && $cHits === 0 && $dLeaks === 0 && $sentOk && $eOk,
        'A=' . (int) $aOk . ' B=' . (int) $bOk . ' C=' . (int) ($cHits === 0) . ' D=' . (int) ($dLeaks === 0 && $sentOk) . ' E=' . (int) $eOk);

    // ---- R3 read-only: every test user row identical after further GETs
    $ids = array_values(array_filter(array_map(fn ($x) => $x[1] ? (int) $x[1]->id : null, $all)));
    $snap = fn () => json_encode(DB::table('v2_user')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    $s0 = $snap(); foreach (array_slice($all, 0, 4) as [$label, $u, , $exp]) if ($u) $get("R3-$label", $u, $exp);
    t_check('R3 repeated GETs leave user rows byte-identical (no write)', $snap() === $s0);
} catch (\Throwable $e) {
    t_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
    Carbon::setTestNow();
}
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
t_check('INV-1 orders / payments / plans / servers / groups / settings / coupons / invite codes / commission logs identical after rollback', $after === $before);
t_check('INV-2 no VPN credential or granted trial left; trial switch still 0, group still unset', DB::table('v2_vpn_credential')->count() === $vpn0 && DB::table('v2_user')->whereNotNull('trial_granted_at')->count() === $granted0
    && (string) DB::table('v2_settings')->where('name', 'manduvpn_trial_enable')->value('value') === '0' && (string) DB::table('v2_settings')->where('name', 'manduvpn_trial_group_id')->value('value') === '');
t_check('INV-3 no temp users / plans / groups left', User::where('email', 'like', 'rollback-test-tr2a%')->count() === 0 && Plan::where('name', 'like', 'rollback-test-tr2a%')->count() === 0
    && DB::table('v2_server_group')->where('name', 'like', 'rollback-test-tr2a%')->count() === 0);
t_check('INV-4 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
