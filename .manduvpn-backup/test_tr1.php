<?php
// ManduVPN TR-1 regression: free trial on signup. ONE rolled-back transaction; the kill switch is only toggled inside it.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{InviteCode, Order, Plan, Server, User};
use App\Services\{AuthService, BasicServerAssignmentService as BSA, IkeV2NodeService as NS, OrderService, ServerService, TrialService, VpnCredentialService as VCS};
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Route, Schema};
use Illuminate\Support\Str;

const E2E_TN = '2026092314093730222674531';
const GIB10 = 10737418240;
$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['register' => $route('Passport\AuthController@register'), 'generate' => $route('Admin\UserController@generate'), 'nodeUsers' => $route('IkeV2NodeApiController@users')];
t_check('setup: routes found (register, admin generate, C4-1C users)', !in_array(null, $R, true), json_encode($R));
$blocked = (int) admin_setting('stop_register', 0) || (int) admin_setting('recaptcha_enable', 0) || (int) admin_setting('email_whitelist_enable', 0);
$emailVerify = (int) admin_setting('email_verify', 0);
t_check('setup: self-service registration is possible with current settings', !$blocked, 'blocked=' . (int) $blocked);
$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = []; foreach (['v2_order', 'v2_payment', 'v2_plan', 'v2_server', 'v2_server_group', 'v2_invite_code', 'v2_commission_log'] as $t) if (Schema::hasTable($t)) $before[$t] = $rows($t);
$counts0 = ['vpn' => DB::table('v2_vpn_credential')->count(), 'node' => DB::table('v2_ikev2_node')->count(), 'granted' => DB::table('v2_user')->whereNotNull('trial_granted_at')->count()];
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$logSizes = []; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) $logSizes[$f] = filesize($f);
$emails = [];

DB::beginTransaction();
try {
    $setSwitch = fn ($v) => DB::table('v2_settings')->where('name', TrialService::SETTING)->update(['value' => $v]);
    $n = 0;
    $group = function ($name = null) use (&$n) { return (int) DB::table('v2_server_group')->insertGetId(['name' => $name ?? ('rollback-test-tr1 group ' . (++$n)), 'created_at' => time(), 'updated_at' => time()]); };
    $server = function (array $gids, $type = 'ikev2', $show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-tr1 ' . Str::random(6), 'group_ids' => array_map('intval', $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-tr1 ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $mkUser = function (array $x = []) use (&$emails) {
        $u = new User(); $u->email = 'rollback-test-tr1-' . Str::lower(Str::random(10)) . '@example.invalid'; $emails[] = $u->email;
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => 0], $x))->save();
        return User::find($u->id);
    };
    $req = function ($method, $uri, array $body, $auth = null, array $hdr = [], $scheme = 'http') use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)] + $hdr;
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $uri = "$scheme://localhost" . $uri;
        $r = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 160)];
    };
    $register = function (array $extra = []) use ($req, $R, $emailVerify, &$emails) {
        $email = 'rollback-test-tr1-reg-' . Str::lower(Str::random(10)) . '@example.invalid'; $emails[] = $email;
        $body = ['email' => $email, 'password' => 'Pw-' . Str::random(16), 'email_code' => ''] + $extra
            // ManduVPN RC: the three mandatory signup consents are now required by the backend gate
            // (RC_CONTRACTS.md §3). A happy-path signup fixture must supply them or every register returns 422.
            + ['consent_version' => \App\Services\ConsentService::currentVersion(), 'consent_locale' => 'ko',
               'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true];
        if ($emailVerify) { $body['email_code'] = (string) random_int(100000, 999999); Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $body['email_code'], 300); }
        [$st, , $raw] = $req('POST', $R['register'], $body);
        return [$st, User::where('email', $email)->first(), $raw];
    };
    $trialState = fn (User $u) => User::find($u->id)->only(['plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'device_limit', 'speed_limit', 'expired_at', 'trial_granted_at', 'assigned_server_id', 'is_partner', 'invite_user_id']);

    // TRIAL group: the real one if exactly one exists, else a rolled-back fixture
    $real = DB::table('v2_server_group')->where('name', 'TRIAL')->pluck('id')->all();
    if (count($real) > 1) throw new RuntimeException('more than one real TRIAL group exists');
    $trial = count($real) === 1 ? (int) $real[0] : $group('TRIAL');
    $other = $group();
    $tIk = $server([$trial]); $tHy = null; try { $tHy = $server([$trial], 'hysteria'); } catch (\Throwable $e) {}
    $tHidden = $server([$trial], 'ikev2', 0); $tMulti = $server([$trial, $other]); $oIk = $server([$other]);
    $temp = array_values(array_filter([$tIk, $tHy, $tHidden, $tMulti, $oIk]));
    $setSwitch('1');

    // ---- T1 grant on signup
    $t0 = time();
    [$st, $u1, $raw] = $register();
    $s1 = $u1 ? $trialState($u1) : [];
    t_check('T1 signup grants the trial: plan NULL, group TRIAL, exactly 10 GiB, u=d=0, device_limit 1, speed NULL, expired = granted + 86400, assigned NULL',
        $st === 200 && $u1 && $s1['plan_id'] === null && (int) $s1['group_id'] === $trial && (int) $s1['transfer_enable'] === GIB10 && (int) $s1['u'] === 0 && (int) $s1['d'] === 0
        && (int) $s1['device_limit'] === 1 && $s1['speed_limit'] === null && (int) $s1['expired_at'] === (int) $s1['trial_granted_at'] + 86400
        && (int) $s1['trial_granted_at'] >= $t0 && (int) $s1['trial_granted_at'] <= time() && $s1['assigned_server_id'] === null, "http=$st " . json_encode($s1));
    t_check('T1b no order row for the trial user (and no payment row: v2_payment unchanged, INV-1)', $u1 && Order::where('user_id', $u1->id)->count() === 0);
    // ---- T2 exactly once
    $again = TrialService::grantOnSignup(User::find($u1->id));
    [$stDup] = $req('POST', $R['register'], ['email' => $u1->email, 'password' => 'Pw-' . Str::random(16), 'email_code' => '',
        // consent supplied so this still proves the DUPLICATE-EMAIL refusal, not the consent refusal
        'consent_version' => \App\Services\ConsentService::currentVersion(), 'consent_locale' => 'ko',
        'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true]);
    t_check('T2 exactly once: repeated grant is a no-op (trial fields unchanged); duplicate email refused', $again === false && $trialState($u1) == $s1 && $stDup !== 200, "dup_http=$stDup");
    // ---- T3 switch off / T4 group missing or duplicate
    $setSwitch('0'); [$st, $u3] = $register(); $setSwitch('1');
    t_check('T3 kill switch 0: signup 200, no trial', $st === 200 && $u3 && $u3->trial_granted_at === null && $u3->plan_id === null && (int) $u3->transfer_enable === 0, "http=$st");
    DB::table('v2_server_group')->where('id', $trial)->update(['name' => 'TRIAL-renamed-by-test']);
    [$st, $u4] = $register();
    DB::table('v2_server_group')->where('id', $trial)->update(['name' => 'TRIAL']);
    $dupG = $group('TRIAL'); [$st2, $u4b] = $register(); DB::table('v2_server_group')->where('id', $dupG)->delete();
    t_check('T4 TRIAL group missing / duplicated: signup 200, no trial', $st === 200 && $st2 === 200 && $u4 && $u4b && $u4->trial_granted_at === null && $u4b->trial_granted_at === null, "http=$st/$st2");
    // ---- T5 invite signup unchanged + same trial
    $inviter = $mkUser(); $code = new InviteCode(); $code->user_id = $inviter->id; $code->code = 'RBTR1' . Str::upper(Str::random(8)); $code->status = 0; $code->save();
    [$st, $u5] = $register(['invite_code' => $code->code]);
    $neverExpire = (int) admin_setting('invite_never_expire', 0);
    $s5 = $u5 ? $trialState($u5) : [];
    [$stBad] = $register(['invite_code' => 'RBTR1-NO-SUCH-' . Str::upper(Str::random(6))]);
    t_check('T5 invite signup: inviter set, code consumed per P1-A, invalid code still 422, identical trial', $st === 200 && $u5 && (int) $s5['invite_user_id'] === (int) $inviter->id
        && (int) InviteCode::find($code->id)->status === ($neverExpire ? 0 : 1) && $stBad === 422 && (int) $s5['transfer_enable'] === GIB10 && $s5['plan_id'] === null
        && (int) $s5['expired_at'] === (int) $s5['trial_granted_at'] + 86400, "http=$st bad=$stBad");
    // ---- T6 partner irrelevant
    $p6 = $mkUser(['is_partner' => 1]); $g6 = TrialService::grant($p6->id, $trial, time());
    t_check('T6 partner status neither blocks nor changes the grant; signup never sets is_partner', $g6 && (int) User::find($p6->id)->is_partner === 1 && (int) User::find($p6->id)->group_id === $trial
        && (int) ($s1['is_partner'] ?? 0) === 0);
    // ---- T7 entitlement (TRIAL group, shown only)
    $vis = array_values(array_intersect(array_map('intval', array_column(ServerService::getAvailableServers(User::find($u1->id)), 'id')), $temp)); sort($vis);
    $expect = array_values(array_filter([$tIk, $tHy, $tMulti])); sort($expect);
    t_check('T7 trial entitlement = every shown TRIAL-group server (IKEv2 + Hysteria2), show=0 and non-TRIAL excluded', $vis === $expect, json_encode($vis) . ' expect ' . json_encode($expect));
    // ---- T8 node lists + C4-1C credential gate
    $inNode = fn (User $u, $sid) => in_array((int) $u->id, ServerService::getAvailableUsers((array) Server::find($sid)->group_ids, (int) $sid)->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
    $tok = NS::issue($tIk);
    $nodeUsers = function () use ($req, $R, $tIk, $tok) { [$st, $j] = $req('GET', $R['nodeUsers'], [], 'Bearer ' . $tok, ['HTTP_X_NODE_ID' => (string) $tIk], 'https'); return [$st, array_column($j['users'] ?? [], 'id')]; };
    [$stA, $idsA] = $nodeUsers();
    VCS::ensure(User::find($u1->id));
    [$stB, $idsB] = $nodeUsers();
    t_check('T8 node list includes the trial user; C4-1C /users excludes it without a credential and includes it once one exists', $inNode(User::find($u1->id), $tIk)
        && $stA === 200 && !in_array((int) $u1->id, $idsA, true) && $stB === 200 && in_array((int) $u1->id, $idsB, true), "http=$stA/$stB");
    // ---- T9 no BSA
    $src = file_get_contents('/www/app/Services/TrialService.php');
    t_check('T9 no BasicServerAssignmentService: not referenced by TrialService, assigned_server_id stays NULL, trial user not counted as BASIC',
        !str_contains($src, 'BasicServerAssignment') && User::find($u1->id)->assigned_server_id === null && (BSA::activeBasicCounts([$tIk])[$tIk] ?? 0) === 0);
    // ---- T10 quota / expiry exclusion
    $q = $mkUser(); TrialService::grant($q->id, $trial, time()); DB::table('v2_user')->where('id', $q->id)->update(['u' => GIB10 / 2, 'd' => GIB10 / 2]);
    $x = $mkUser(); TrialService::grant($x->id, $trial, time() - 86401);
    t_check('T10 quota used up (u+d = 10 GiB) or 24 h passed: excluded from node lists', !$inNode(User::find($q->id), $tIk) && !$inNode(User::find($x->id), $tIk) && $inNode(User::find($u1->id), $tIk));
    // ---- T11 purchase during active trial (Option A)
    $pg = $group(); $pIk = $server([$pg]); $pro = $plan($pg, 'pro'); $basic = $plan($pg, 'basic');
    $buy = function (User $u, Plan $p) {
        $o = new Order(['user_id' => $u->id, 'plan_id' => $p->id, 'period' => 'month_price', 'trade_no' => 'RBTR1' . Str::random(20), 'total_amount' => 1000]);
        (new OrderService($o))->setOrderType(User::find($u->id));
        $o->status = Order::STATUS_PROCESSING; $o->save();
        (new OrderService(Order::find($o->id)))->open();
        return Order::find($o->id);
    };
    DB::table('v2_user')->where('id', $u5->id)->update(['u' => 123456789, 'd' => 987654321]);
    $before11 = $trialState($u5);
    $o11 = $buy(User::find($u5->id), $pro); $a11 = $trialState($u5);
    t_check('T11 active-trial purchase: NEW_PURCHASE; paid plan/group/quota/device replace TRIAL at once; u/d reset; expiry = trial expiry + 1 month (Option A); evidence kept',
        (int) $o11->type === Order::TYPE_NEW_PURCHASE && (int) $a11['plan_id'] === (int) $pro->id && (int) $a11['group_id'] === $pg && (int) $a11['transfer_enable'] === 100 * 1073741824
        && (int) $a11['u'] === 0 && (int) $a11['d'] === 0 && (int) $a11['device_limit'] === 2 && (int) $a11['expired_at'] === strtotime('+1 month', (int) $before11['expired_at'])
        && (int) $a11['trial_granted_at'] === (int) $before11['trial_granted_at'] && $a11['assigned_server_id'] === null, json_encode($a11));
    $ub = $mkUser(); TrialService::grant($ub->id, $trial, time());
    $o11b = $buy(User::find($ub->id), $basic);
    t_check('T11b BASIC purchase during a trial still gets the normal PP-C2 assignment', (int) $o11b->type === Order::TYPE_NEW_PURCHASE && (int) User::find($ub->id)->assigned_server_id === $pIk);
    // ---- T12 purchase after the trial expired
    $ue = $mkUser(); TrialService::grant($ue->id, $trial, time() - 86401);
    $t12 = time(); $o12 = $buy(User::find($ue->id), $pro); $e12 = (int) User::find($ue->id)->expired_at;
    t_check('T12 purchase after trial expiry: period starts from now', (int) $o12->type === Order::TYPE_NEW_PURCHASE && $e12 >= strtotime('+1 month', $t12) && $e12 <= strtotime('+1 month', time()));
    // ---- T13 legacy try_out fixture (user already has a plan) is never double-granted
    $lt = $mkUser(['plan_id' => $pro->id, 'group_id' => $pg, 'transfer_enable' => 5 * 1073741824, 'expired_at' => time() + 3600]);
    $sLt = $trialState($lt);
    t_check('T13 account that already holds a plan (legacy try_out style) is not granted a trial', TrialService::grantOnSignup(User::find($lt->id)) === false && $trialState($lt) == $sLt);
    // ---- T14 admin-generated users get no trial
    $admin = $mkUser(['is_admin' => 1]); $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $prefix = 'rollback-test-tr1-gen-' . Str::lower(Str::random(6)); $emails[] = "$prefix@example.invalid";
    [$st] = $req('POST', $R['generate'], ['email_prefix' => $prefix, 'email_suffix' => 'example.invalid'], $adminAuth);
    $gu = User::where('email', "$prefix@example.invalid")->first();
    t_check('T14 admin user/generate: user created without a trial', $st === 200 && $gu && $gu->trial_granted_at === null && $gu->plan_id === null && (int) $gu->transfer_enable === 0, "http=$st");
    // ---- T15 forced grant failure never fails signup, no partial state
    DB::statement("CREATE TRIGGER tr1_forced_failure BEFORE UPDATE OF trial_granted_at ON v2_user BEGIN SELECT RAISE(ABORT, 'forced by TR-1 test'); END");
    [$st, $u15] = $register();
    DB::statement('DROP TRIGGER tr1_forced_failure');
    $s15 = $u15 ? $trialState($u15) : [];
    t_check('T15 forced grant failure: signup 200, no partial trial state', $st === 200 && $u15 && $s15['trial_granted_at'] === null && $s15['group_id'] === null && (int) $s15['transfer_enable'] === 0
        && $s15['expired_at'] === null && $s15['device_limit'] === null, "http=$st " . json_encode($s15));
    // ---- T16 repeated / racing grants: exactly one effect
    $r = $mkUser(); $n1 = time() - 10; $n2 = time();
    $g1 = TrialService::grant($r->id, $trial, $n1); $g2 = TrialService::grant($r->id, $trial, $n2); $g3 = TrialService::grantOnSignup(User::find($r->id));
    t_check('T16 repeated/racing grants: exactly one effect (first wins, later calls no-op)', $g1 === true && $g2 === false && $g3 === false && (int) User::find($r->id)->trial_granted_at === $n1
        && (int) User::find($r->id)->expired_at === $n1 + 86400);
} catch (\Throwable $e) {
    t_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
t_check('INV-1 orders / payments / plans / servers / groups / invite codes / commission logs identical after rollback', $after === $before);
t_check('INV-2 no VPN credential, no IKEv2 node token, no granted trial remain; switch still 0', DB::table('v2_vpn_credential')->count() === $counts0['vpn'] && DB::table('v2_ikev2_node')->count() === $counts0['node']
    && DB::table('v2_user')->whereNotNull('trial_granted_at')->count() === $counts0['granted'] && (string) DB::table('v2_settings')->where('name', 'manduvpn_trial_enable')->value('value') === '0');
t_check('INV-3 no temp users left', User::where('email', 'like', 'rollback-test-tr1%')->count() === 0);
t_check('INV-4 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
$new = ''; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) { $h = fopen($f, 'r'); fseek($h, $logSizes[$f] ?? 0); $new .= (string) stream_get_contents($h); fclose($h); }
$leak = false; foreach ($emails as $em) if (str_contains($new, $em)) $leak = true;
t_check('INV-5 TR-1 log lines carry safe identifiers only (no test email in new Laravel log output)', !$leak);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
