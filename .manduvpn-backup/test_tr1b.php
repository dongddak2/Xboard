<?php
// ManduVPN TR-1b regression: admin-managed trial policy + one-time trial on signup. ONE rolled-back transaction.
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
const GIB = 1073741824;
$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = ['register' => $route('Passport\AuthController@register'), 'generate' => $route('Admin\UserController@generate'), 'nodeUsers' => $route('IkeV2NodeApiController@users'),
      'pFetch' => $route('TrialPolicyController@fetch'), 'pSave' => $route('TrialPolicyController@save')];
t_check('setup: routes found (register, generate, C4-1C users, trial policy fetch/save)', !in_array(null, $R, true), json_encode($R));
$blocked = (int) admin_setting('stop_register', 0) || (int) admin_setting('recaptcha_enable', 0) || (int) admin_setting('email_whitelist_enable', 0);
$emailVerify = (int) admin_setting('email_verify', 0);
t_check('setup: self-service registration is possible with current settings', !$blocked, 'blocked=' . (int) $blocked);
$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = []; foreach (['v2_order', 'v2_payment', 'v2_plan', 'v2_server', 'v2_server_group', 'v2_invite_code', 'v2_commission_log', 'v2_settings'] as $t) if (Schema::hasTable($t)) $before[$t] = $rows($t);
$counts0 = ['vpn' => DB::table('v2_vpn_credential')->count(), 'node' => DB::table('v2_ikev2_node')->count(), 'granted' => DB::table('v2_user')->whereNotNull('trial_granted_at')->count()];
$e2eBefore = md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes()));
$logSizes = []; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) $logSizes[$f] = filesize($f);
$emails = [];

DB::beginTransaction();
try {
    $setPolicy = function (array $p) { foreach ($p as $k => $v) DB::table('v2_settings')->where('name', TrialService::KEYS[$k])->update(['value' => (string) $v]); };
    $n = 0;
    $group = function () use (&$n) { return (int) DB::table('v2_server_group')->insertGetId(['name' => 'rollback-test-tr1b group ' . (++$n), 'created_at' => time(), 'updated_at' => time()]); };
    $server = function (array $gids, $type = 'ikev2', $show = 1, $parent = null) {
        $s = new Server(); $s->forceFill(['type' => $type, 'name' => 'rollback-test-tr1b ' . Str::random(6), 'group_ids' => array_map('intval', $gids), 'route_ids' => [], 'rate' => 1,
            'host' => '192.0.2.' . random_int(1, 250), 'port' => '500', 'server_port' => 500, 'protocol_settings' => [], 'show' => $show, 'sort' => 9999, 'parent_id' => $parent])->save();
        return (int) $s->id;
    };
    $plan = function ($gid, $tier) {
        $p = new Plan(); $p->forceFill(['name' => 'rollback-test-tr1b ' . ($tier ?? 'legacy'), 'group_id' => $gid, 'transfer_enable' => 100, 'speed_limit' => null, 'device_limit' => 2,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 9999, 'content' => '', 'reset_traffic_method' => 0, 'capacity_limit' => null,
            'prices' => ['days_30' => 10], 'prices_krw' => ['days_30' => 2000], 'audience' => 'public', 'service_tier' => $tier])->save();
        return Plan::find($p->id);
    };
    $mkUser = function (array $x = []) use (&$emails) {
        $u = new User(); $u->email = 'rollback-test-tr1b-' . Str::lower(Str::random(10)) . '@example.invalid'; $emails[] = $u->email;
        $u->password = password_hash(Str::random(24), PASSWORD_DEFAULT); $u->uuid = (string) Str::uuid(); $u->token = Str::random(32); $u->balance = 0;
        $u->forceFill(array_merge(['transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => 0], $x))->save();
        return User::find($u->id);
    };
    $req = function ($method, $uri, array $body, $auth = null, array $hdr = [], $scheme = 'http') use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.252.' . random_int(1, 254) . '.' . random_int(1, 254)] + $hdr;
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $uri = "$scheme://localhost" . $uri;
        $r = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 200)];
    };
    $register = function (array $extra = []) use ($req, $R, $emailVerify, &$emails) {
        $email = 'rollback-test-tr1b-reg-' . Str::lower(Str::random(10)) . '@example.invalid'; $emails[] = $email;
        $body = ['email' => $email, 'password' => 'Pw-' . Str::random(16), 'email_code' => ''] + $extra
            // ManduVPN RC: the three mandatory signup consents are now required by the backend gate
            // (RC_CONTRACTS.md §3). A happy-path signup fixture must supply them or every register returns 422.
            + ['consent_version' => \App\Services\ConsentService::currentVersion(), 'consent_locale' => 'ko',
               'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true];
        if ($emailVerify) { $body['email_code'] = (string) random_int(100000, 999999); Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $body['email_code'], 300); }
        [$st, , $raw] = $req('POST', $R['register'], $body);
        return [$st, User::where('email', $email)->first(), $raw];
    };
    $ENT = ['plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'device_limit', 'speed_limit', 'expired_at', 'trial_granted_at', 'assigned_server_id'];
    $state = fn (User $u) => User::find($u->id)->only(array_merge($ENT, ['is_partner', 'invite_user_id']));
    $ent = fn (User $u) => User::find($u->id)->only($ENT);

    $trial = $group(); $other = $group();
    $tIk = $server([$trial]); $tHy = null; try { $tHy = $server([$trial], 'hysteria'); } catch (\Throwable $e) {}
    $tHidden = $server([$trial], 'ikev2', 0); $tMulti = $server([$trial, $other]); $oIk = $server([$other]);
    $temp = array_values(array_filter([$tIk, $tHy, $tHidden, $tMulti, $oIk]));
    $setPolicy(['enable' => '1', 'duration_hours' => '24', 'transfer_gb' => '10', 'device_limit' => '1', 'speed_limit' => '', 'group_id' => (string) $trial]);

    // ---- T1
    $t0 = time();
    [$st, $u1] = $register();
    $s1 = $u1 ? $state($u1) : [];
    t_check('T1 signup grants the policy snapshot: plan NULL, group = policy group, 10 GiB, u=d=0, device 1, speed NULL, expired = granted + 24h, assigned NULL',
        $st === 200 && $u1 && $s1['plan_id'] === null && (int) $s1['group_id'] === $trial && (int) $s1['transfer_enable'] === 10 * GIB && (int) $s1['u'] === 0 && (int) $s1['d'] === 0
        && (int) $s1['device_limit'] === 1 && $s1['speed_limit'] === null && (int) $s1['expired_at'] === (int) $s1['trial_granted_at'] + 86400
        && (int) $s1['trial_granted_at'] >= $t0 && (int) $s1['trial_granted_at'] <= time() && $s1['assigned_server_id'] === null, "http=$st " . json_encode($s1));
    t_check('T1b no order row (payment table unchanged: INV-1)', $u1 && Order::where('user_id', $u1->id)->count() === 0);
    // ---- T2
    $again = TrialService::grantOnSignup(User::find($u1->id));
    [$stDup] = $req('POST', $R['register'], ['email' => $u1->email, 'password' => 'Pw-' . Str::random(16), 'email_code' => '',
        // consent supplied so this still proves the DUPLICATE-EMAIL refusal, not the consent refusal
        'consent_version' => \App\Services\ConsentService::currentVersion(), 'consent_locale' => 'ko',
        'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true]);
    t_check('T2 exactly once: repeated grant no-op; duplicate email refused', $again === false && $state($u1) == $s1 && $stDup !== 200, "dup_http=$stDup");
    // ---- T3 kill switch off (baseline for T15)
    $setPolicy(['enable' => '0']); [$st, $u3] = $register(); $setPolicy(['enable' => '1']);
    $base = $u3 ? $ent($u3) : [];
    t_check('T3 enable=0: signup 200, no trial (baseline entitlement recorded)', $st === 200 && $u3 && $base['trial_granted_at'] === null && $base['plan_id'] === null && (int) $base['transfer_enable'] === 0, "http=$st " . json_encode($base));
    // ---- T4 group unset
    $setPolicy(['group_id' => '']); [$st, $u4] = $register(); $setPolicy(['group_id' => (string) $trial]);
    t_check('T4 policy group unset: signup 200, no trial', $st === 200 && $u4 && $u4->trial_granted_at === null, "http=$st");
    // ---- T5 invite
    $inviter = $mkUser(); $code = new InviteCode(); $code->user_id = $inviter->id; $code->code = 'RBTR1B' . Str::upper(Str::random(8)); $code->status = 0; $code->save();
    [$st, $u5] = $register(['invite_code' => $code->code]);
    $neverExpire = (int) admin_setting('invite_never_expire', 0);
    $s5 = $u5 ? $state($u5) : [];
    [$stBad] = $register(['invite_code' => 'RBTR1B-NO-SUCH-' . Str::upper(Str::random(6))]);
    t_check('T5 invite signup: inviter set, code consumed per P1-A, invalid code still 422, identical trial', $st === 200 && $u5 && (int) $s5['invite_user_id'] === (int) $inviter->id
        && (int) InviteCode::find($code->id)->status === ($neverExpire ? 0 : 1) && $stBad === 422 && (int) $s5['transfer_enable'] === 10 * GIB && $s5['plan_id'] === null
        && (int) $s5['expired_at'] === (int) $s5['trial_granted_at'] + 86400, "http=$st bad=$stBad");
    // ---- T6
    [$pol] = TrialService::policy();
    $p6 = $mkUser(['is_partner' => 1]); $g6 = TrialService::grant($p6->id, $pol, time());
    t_check('T6 partner status neither blocks nor changes the grant; signup never sets is_partner', $g6 && (int) User::find($p6->id)->is_partner === 1 && (int) User::find($p6->id)->group_id === $trial && (int) ($s1['is_partner'] ?? 0) === 0);
    // ---- T7
    $vis = array_values(array_intersect(array_map('intval', array_column(ServerService::getAvailableServers(User::find($u1->id)), 'id')), $temp)); sort($vis);
    $expect = array_values(array_filter([$tIk, $tHy, $tMulti])); sort($expect);
    t_check('T7 entitlement = every shown server of the policy group (IKEv2 + Hysteria2); show=0 and other groups excluded', $vis === $expect, json_encode($vis) . ' expect ' . json_encode($expect));
    // ---- T8
    $inNode = fn (User $u, $sid) => in_array((int) $u->id, ServerService::getAvailableUsers((array) Server::find($sid)->group_ids, (int) $sid)->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
    $tok = NS::issue($tIk);
    $nodeUsers = function () use ($req, $R, $tIk, $tok) { [$st, $j] = $req('GET', $R['nodeUsers'], [], 'Bearer ' . $tok, ['HTTP_X_NODE_ID' => (string) $tIk], 'https'); return [$st, array_column($j['users'] ?? [], 'id')]; };
    [$stA, $idsA] = $nodeUsers(); VCS::ensure(User::find($u1->id)); [$stB, $idsB] = $nodeUsers();
    t_check('T8 node list includes the trial user; C4-1C /users excludes it without a credential, includes it once one exists', $inNode(User::find($u1->id), $tIk)
        && $stA === 200 && !in_array((int) $u1->id, $idsA, true) && $stB === 200 && in_array((int) $u1->id, $idsB, true), "http=$stA/$stB");
    // ---- T9
    $src = file_get_contents('/www/app/Services/TrialService.php');
    t_check('T9 no BasicServerAssignmentService: not referenced, assigned_server_id NULL, trial user not counted as BASIC',
        !str_contains($src, 'BasicServerAssignment') && User::find($u1->id)->assigned_server_id === null && (BSA::activeBasicCounts([$tIk])[$tIk] ?? 0) === 0);
    // ---- T10
    $q = $mkUser(); TrialService::grant($q->id, $pol, time()); DB::table('v2_user')->where('id', $q->id)->update(['u' => 5 * GIB, 'd' => 5 * GIB]);
    $x = $mkUser(); TrialService::grant($x->id, $pol, time() - 86401);
    t_check('T10 quota used up or duration passed: excluded from node lists', !$inNode(User::find($q->id), $tIk) && !$inNode(User::find($x->id), $tIk) && $inNode(User::find($u1->id), $tIk));
    // ---- T11 purchase during active trial (Option A)
    $pg = $group(); $pIk = $server([$pg]); $pro = $plan($pg, 'pro'); $basic = $plan($pg, 'legacy');
    $buy = function (User $u, Plan $p) {
        $o = new Order(['user_id' => $u->id, 'plan_id' => $p->id, 'period' => 'month_price', 'trade_no' => 'RBTR1B' . Str::random(20), 'total_amount' => 1000]);
        (new OrderService($o))->setOrderType(User::find($u->id));
        $o->status = Order::STATUS_PROCESSING; $o->save();
        (new OrderService(Order::find($o->id)))->open();
        return Order::find($o->id);
    };
    DB::table('v2_user')->where('id', $u5->id)->update(['u' => 123456789, 'd' => 987654321]);
    $b11 = $state($u5); $o11 = $buy(User::find($u5->id), $pro); $a11 = $state($u5);
    t_check('T11 active-trial purchase: NEW_PURCHASE; paid plan/group/quota/device replace the trial at once; u/d reset; expiry = trial expiry + 1 month (Option A); evidence kept',
        (int) $o11->type === Order::TYPE_NEW_PURCHASE && (int) $a11['plan_id'] === (int) $pro->id && (int) $a11['group_id'] === $pg && (int) $a11['transfer_enable'] === 100 * GIB
        && (int) $a11['u'] === 0 && (int) $a11['d'] === 0 && (int) $a11['device_limit'] === 2 && (int) $a11['expired_at'] === strtotime('+1 month', (int) $b11['expired_at'])
        && (int) $a11['trial_granted_at'] === (int) $b11['trial_granted_at'] && $a11['assigned_server_id'] === null, json_encode($a11));
    $ub = $mkUser(); TrialService::grant($ub->id, $pol, time()); $o11b = $buy(User::find($ub->id), $basic);
    t_check('T11b BASIC purchase during a trial still gets the normal PP-C2 assignment', (int) $o11b->type === Order::TYPE_NEW_PURCHASE && (int) User::find($ub->id)->assigned_server_id === $pIk);
    // ---- T12
    $ue = $mkUser(); TrialService::grant($ue->id, $pol, time() - 86401);
    $t12 = time(); $o12 = $buy(User::find($ue->id), $pro); $e12 = (int) User::find($ue->id)->expired_at;
    t_check('T12 purchase after trial expiry: period starts from now', (int) $o12->type === Order::TYPE_NEW_PURCHASE && $e12 >= strtotime('+1 month', $t12) && $e12 <= strtotime('+1 month', time()));
    // ---- T13
    $lt = $mkUser(['plan_id' => $pro->id, 'group_id' => $pg, 'transfer_enable' => 5 * GIB, 'expired_at' => time() + 3600]); $sLt = $state($lt);
    t_check('T13 account that already holds a plan (legacy try_out style) is not granted a trial', TrialService::grantOnSignup(User::find($lt->id)) === false && $state($lt) == $sLt);
    // ---- T14
    $admin = $mkUser(['is_admin' => 1]); $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $prefix = 'rollback-test-tr1b-gen-' . Str::lower(Str::random(6)); $emails[] = "$prefix@example.invalid";
    [$st] = $req('POST', $R['generate'], ['email_prefix' => $prefix, 'email_suffix' => 'example.invalid'], $adminAuth);
    $gu = User::where('email', "$prefix@example.invalid")->first();
    t_check('T14 admin user/generate: user created without a trial', $st === 200 && $gu && $gu->trial_granted_at === null && $gu->plan_id === null && (int) $gu->transfer_enable === 0, "http=$st");
    // ---- T15 (harness fix: compare with the T3 no-trial signup baseline, never a hard-coded NULL)
    DB::statement("CREATE TRIGGER tr1b_forced_failure BEFORE UPDATE OF trial_granted_at ON v2_user BEGIN SELECT RAISE(ABORT, 'forced by TR-1b test'); END");
    [$st, $u15] = $register();
    DB::statement('DROP TRIGGER tr1b_forced_failure');
    $e15 = $u15 ? $ent($u15) : [];
    t_check('T15 forced grant failure: signup 200 and entitlement identical to a no-trial signup (T3 baseline)', $st === 200 && $u15 && $base && $e15 === $base, "http=$st got=" . json_encode($e15) . ' baseline=' . json_encode($base));
    // ---- T16 exactly once under every circumstance
    $r = $mkUser(); $n1 = time() - 10;
    $g1 = TrialService::grant($r->id, $pol, $n1); $g2 = TrialService::grant($r->id, $pol, time()); $g3 = TrialService::grantOnSignup(User::find($r->id));
    $noRe = [TrialService::grantOnSignup(User::find($x->id)), TrialService::grantOnSignup(User::find($q->id)), TrialService::grantOnSignup(User::find($u5->id))];
    $setPolicy(['enable' => '0']); $setPolicy(['enable' => '1', 'duration_hours' => '168', 'transfer_gb' => '50']);
    $noRe[] = TrialService::grantOnSignup(User::find($u1->id));
    $setPolicy(['duration_hours' => '24', 'transfer_gb' => '10']);
    t_check('T16 exactly one effect: repeated/racing grants no-op; no re-grant after expiry, quota use, paid purchase, policy change or disable/re-enable',
        $g1 === true && $g2 === false && $g3 === false && (int) User::find($r->id)->trial_granted_at === $n1 && !in_array(true, $noRe, true) && $state($u1)['trial_granted_at'] === $s1['trial_granted_at']);

    // ---- P1 policy changes affect only future grants (through the real admin API)
    $snapU1 = $ent($u1);
    [$st] = $req('POST', $R['pSave'], ['enable' => true, 'duration_hours' => 72, 'transfer_gb' => 30, 'device_limit' => 2, 'speed_limit' => 50, 'group_id' => $trial], $adminAuth);
    [$stN, $uN] = $register(); $sN = $uN ? $ent($uN) : [];
    t_check('P1 saved policy (72h/30 GB/2 devices/50 Mbps) applies to the next signup only; earlier trial user unchanged', $st === 200 && $stN === 200 && $uN && (int) $sN['transfer_enable'] === 30 * GIB
        && (int) $sN['device_limit'] === 2 && (int) $sN['speed_limit'] === 50 && (int) $sN['expired_at'] === (int) $sN['trial_granted_at'] + 72 * 3600 && $ent($u1) === $snapU1, "save=$st signup=$stN");
    // ---- P2 invalid values rejected, nothing stored
    $good = ['enable' => true, 'duration_hours' => 24, 'transfer_gb' => 10, 'device_limit' => 1, 'speed_limit' => null, 'group_id' => $trial];
    $settingsHash = fn () => json_encode(DB::table('v2_settings')->where('name', 'like', 'manduvpn_trial_%')->orderBy('name')->pluck('value', 'name'));
    $h0 = $settingsHash();
    $bad = [['duration_hours' => 0], ['duration_hours' => 721], ['duration_hours' => 1.5], ['duration_hours' => '24h'], ['transfer_gb' => 0], ['transfer_gb' => 1001], ['device_limit' => 0], ['device_limit' => 11],
        ['speed_limit' => 0], ['speed_limit' => -5], ['speed_limit' => 'fast'], ['group_id' => 999999999], ['group_id' => 'abc'], ['enable' => 'yes'], ['enable' => true, 'group_id' => null], ['transfer_gb' => true]];
    $codes = [];
    foreach ($bad as $b) { [$s] = $req('POST', $R['pSave'], array_merge($good, $b), $adminAuth); $codes[] = $s; }
    $missing = $good; unset($missing['device_limit']); [$sm] = $req('POST', $R['pSave'], $missing, $adminAuth); $codes[] = $sm;
    t_check('P2 invalid / out-of-range / missing policy values -> 422 (17 cases), stored policy unchanged', array_values(array_unique($codes)) === [422] && $settingsHash() === $h0, implode(',', $codes));
    // ---- P3 unset / invalid stored group (or other stored value) -> signup OK without trial
    $setPolicy(['group_id' => '999999999']); [$s3a, $u3a] = $register();
    $setPolicy(['group_id' => (string) $trial, 'duration_hours' => '0']); [$s3b, $u3b] = $register();
    $setPolicy(['duration_hours' => '24', 'group_id' => '']); [$s3c, $u3c] = $register();
    $setPolicy(['group_id' => (string) $trial]);
    t_check('P3 nonexistent group, invalid stored duration or unset group: signup 200 without trial', $s3a === 200 && $s3b === 200 && $s3c === 200 && $u3a && $u3b && $u3c
        && $u3a->trial_granted_at === null && $u3b->trial_granted_at === null && $u3c->trial_granted_at === null, "$s3a/$s3b/$s3c");
    // ---- P4 72h / 168h exact
    [$pol72] = TrialService::validate(['enable' => '1', 'duration_hours' => '72', 'transfer_gb' => '10', 'device_limit' => '1', 'speed_limit' => '', 'group_id' => (string) $trial]);
    [$pol168] = TrialService::validate(['enable' => '1', 'duration_hours' => '168', 'transfer_gb' => '10', 'device_limit' => '1', 'speed_limit' => '', 'group_id' => (string) $trial]);
    $a4 = $mkUser(); $b4 = $mkUser(); $now4 = time();
    TrialService::grant($a4->id, $pol72, $now4); TrialService::grant($b4->id, $pol168, $now4);
    t_check('P4 72 h and 168 h expiries are exact (259200 s / 604800 s)', (int) User::find($a4->id)->expired_at === $now4 + 259200 && (int) User::find($b4->id)->expired_at === $now4 + 604800);
    // ---- P5 non-admin rejected (baseline taken immediately before the rejected requests: P3 legitimately edits the
    //      policy through fixture writes, so the P2 baseline $h0 is stale here)
    $plainAuth = (new AuthService($mkUser()))->generateAuthData()['auth_data'];
    $p5Before = TrialService::rawPolicy(); $p5RowsBefore = $settingsHash();
    [$f1] = $req('GET', $R['pFetch'], [], $plainAuth); [$f2] = $req('POST', $R['pSave'], $good, $plainAuth); [$f3] = $req('GET', $R['pFetch'], []); [$f4] = $req('POST', $R['pSave'], $good);
    $p5After = TrialService::rawPolicy(); $p5Unchanged = $p5After === $p5Before && $settingsHash() === $p5RowsBefore;
    t_check('P5 non-admin (403) and unauthenticated (401/403) policy fetch/save rejected; stored policy unchanged', $f1 === 403 && $f2 === 403 && in_array($f3, [401, 403], true) && in_array($f4, [401, 403], true) && $p5Unchanged,
        "non_admin_fetch_status=$f1 non_admin_save_status=$f2 unauth_fetch_status=$f3 unauth_save_status=$f4 policy_before=" . json_encode($p5Before) . ' policy_after=' . json_encode($p5After) . ' policy_unchanged=' . ($p5Unchanged ? 'true' : 'false'));
    // ---- P6 saving never mutates existing trial users; zero shown nodes -> saved with warning; fetch reports state
    $trialUsers = DB::table('v2_user')->whereNotNull('trial_granted_at')->orderBy('id')->get($ENT)->map(fn ($r) => (array) $r)->all();
    $emptyG = $group(); $server([$emptyG], 'ikev2', 0);
    [$s6, $j6] = $req('POST', $R['pSave'], array_merge($good, ['group_id' => $emptyG, 'duration_hours' => 168, 'transfer_gb' => 100, 'device_limit' => 3, 'speed_limit' => 20]), $adminAuth);
    [$sf, $jf] = $req('GET', $R['pFetch'], [], $adminAuth);
    $after6 = DB::table('v2_user')->whereNotNull('trial_granted_at')->orderBy('id')->get($ENT)->map(fn ($r) => (array) $r)->all();
    t_check('P6 policy save never touches existing trial users; group with no shown node saved with GROUP_HAS_NO_SHOWN_NODES warning; fetch returns the stored policy',
        $s6 === 200 && in_array('GROUP_HAS_NO_SHOWN_NODES', $j6['data']['warnings'] ?? [], true) && $after6 === $trialUsers && count($trialUsers) > 5
        && $sf === 200 && ($jf['data']['policy']['group_id'] ?? null) === $emptyG && ($jf['data']['policy']['duration_hours'] ?? null) === 168 && ($jf['data']['valid'] ?? false) === true, "save=$s6 fetch=$sf");
} catch (\Throwable $e) {
    t_check('test run', false, class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
}
$after = []; foreach (array_keys($before) as $t) $after[$t] = $rows($t);
t_check('INV-1 orders / payments / plans / servers / groups / invite codes / commission logs / settings identical after rollback', $after === $before);
t_check('INV-2 no VPN credential, no IKEv2 node token, no granted trial remain; switch still 0', DB::table('v2_vpn_credential')->count() === $counts0['vpn'] && DB::table('v2_ikev2_node')->count() === $counts0['node']
    && DB::table('v2_user')->whereNotNull('trial_granted_at')->count() === $counts0['granted'] && (string) DB::table('v2_settings')->where('name', 'manduvpn_trial_enable')->value('value') === '0');
t_check('INV-3 no temp users left', User::where('email', 'like', 'rollback-test-tr1b%')->count() === 0);
t_check('INV-4 protected E2E order unchanged', md5(json_encode(optional(Order::where('trade_no', E2E_TN)->first())->getAttributes())) === $e2eBefore);
$new = ''; foreach (glob(storage_path('logs') . '/*.log') ?: [] as $f) { $h = fopen($f, 'r'); fseek($h, $logSizes[$f] ?? 0); $new .= (string) stream_get_contents($h); fclose($h); }
$leak = false; foreach ($emails as $em) if (str_contains($new, $em)) $leak = true;
t_check('INV-5 TR-1b log lines carry safe identifiers only (no test email in new Laravel log output)', !$leak);
echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
