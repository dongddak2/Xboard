<?php
// ManduVPN RC regression: the three mandatory signup consents (RC_CONTRACTS.md §3). ONE rolled-back transaction.
// Proves the BACKEND is the gate: a direct API call that skips the web checkboxes is refused with 422 and
// leaves no user row and no consumed invite code behind.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{InviteCode, User};
use App\Services\{AuthService, ConsentService};
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Route, Schema};
use Illuminate\Support\Str;

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }
app('eventy')->removeAllActions('order.after_create');
Http::preventStrayRequests();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$route = function ($a) { $r = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_ends_with($r->getActionName(), $a)); return $r ? '/' . $r->uri() : null; };
$R = [
    'register' => $route('Passport\AuthController@register'),
    'terms' => $route('Guest\TermsController@current'),
    'adminFetch' => $route('Admin\ConsentTermsController@fetch'),
    'adminSave' => $route('Admin\ConsentTermsController@save'),
    'adminHistory' => $route('Admin\ConsentTermsController@userHistory'),
];
t_check('setup: routes found (register, guest terms, admin consentTerms fetch/save/history)', !in_array(null, $R, true), json_encode($R));
t_check('setup: v2_user_consent table exists', Schema::hasTable('v2_user_consent'));
$blocked = (int) admin_setting('stop_register', 0) || (int) admin_setting('recaptcha_enable', 0) || (int) admin_setting('email_whitelist_enable', 0);
$emailVerify = (int) admin_setting('email_verify', 0);
t_check('setup: self-service registration is possible with current settings', !$blocked, 'blocked=' . (int) $blocked);

// Before/after snapshots: nothing outside the rolled-back transaction may change.
$rows = fn ($t) => DB::table($t)->count() . ':' . substr(hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 0, 16);
$before = [];
foreach (['v2_user_consent', 'v2_settings', 'v2_invite_code', 'v2_order', 'v2_plan', 'v2_payment'] as $t) {
    if (Schema::hasTable($t)) $before[$t] = $rows($t);
}
$usersBefore = DB::table('v2_user')->count();

DB::beginTransaction();
try {
    $req = function ($method, $uri, array $body, $auth = null, array $hdr = []) use ($http) {
        try { app('auth')->forgetGuards(); } catch (\Throwable $e) {}
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.253.' . random_int(1, 254) . '.' . random_int(1, 254)] + $hdr;
        if ($auth) $server['HTTP_AUTHORIZATION'] = $auth;
        $uri = 'http://localhost' . $uri;
        $r = $method === 'GET' ? Request::create($uri, 'GET', $body, [], [], $server) : Request::create($uri, $method, [], [], [], $server, json_encode($body));
        $res = $http->handle($r);
        return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], substr((string) $res->getContent(), 0, 300)];
    };
    $goodConsent = fn () => [
        'consent_version' => ConsentService::currentVersion(),
        'consent_locale' => 'ko',
        'consent_identity' => true,
        'consent_account_sharing' => true,
        'consent_payment_refund' => true,
    ];
    $register = function (array $extra = [], bool $withConsent = true) use ($req, $R, $emailVerify, $goodConsent) {
        $email = 'rollback-test-consent-' . Str::lower(Str::random(10)) . '@example.invalid';
        $body = ['email' => $email, 'password' => 'Pw-' . Str::random(16), 'email_code' => ''] + $extra;
        if ($withConsent) $body += $goodConsent();
        if ($emailVerify) { $body['email_code'] = (string) random_int(100000, 999999); Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $body['email_code'], 300); }
        [$st, $j, $raw] = $req('POST', $R['register'], $body);
        return [$st, User::where('email', $email)->first(), $j, $raw, $email];
    };

    // ---- C1 guest terms API: 4 locales x 3 sections, all fields present
    $termsOk = true; $termsDetail = [];
    foreach (ConsentService::LOCALES as $loc) {
        [$st, $j] = $req('GET', $R['terms'], ['locale' => $loc]);
        $d = $j['data'] ?? [];
        $keys = array_column($d['sections'] ?? [], 'key');
        $complete = ($st === 200)
            && ($d['locale'] ?? null) === $loc
            && ($d['version'] ?? '') === ConsentService::currentVersion()
            && $keys === ConsentService::SECTIONS
            && collect($d['sections'] ?? [])->every(fn ($s) => trim((string) $s['title']) !== '' && trim((string) $s['body']) !== '' && trim((string) $s['checkbox_label']) !== '' && is_array($s['warnings']));
        $termsDetail[$loc] = $st . '/' . count($keys);
        if (!$complete) $termsOk = false;
    }
    t_check('C1 guest terms: 4 locales x 3 sections, non-empty title/body/checkbox_label, warnings array, version matches', $termsOk, json_encode($termsDetail));
    [, $jPr] = $req('GET', $R['terms'], ['locale' => 'ko']);
    $pr = collect($jPr['data']['sections'] ?? [])->firstWhere('key', 'payment_refund');
    t_check('C1b payment_refund keeps the statutory clause and states no unconditional refusal',
        $pr && str_contains($pr['title'] . $pr['body'] . implode('', $pr['warnings']), ConsentService::REFUND_CLAUSE_MARKER['ko'])
        && !preg_match(ConsentService::REFUND_ABSOLUTE_PATTERNS['ko'], $pr['title'] . $pr['body'] . implode('', $pr['warnings'])));
    [$stBadLoc, $jBadLoc] = $req('GET', $R['terms'], ['locale' => 'de']);
    t_check('C1c unknown locale falls back to ko rather than erroring', $stBadLoc === 200 && ($jBadLoc['data']['locale'] ?? null) === 'ko', "http=$stBadLoc");

    // ---- C2 the gate: every incomplete consent is refused with 422 and creates NO user
    $cases = [
        'no consent params at all' => [[], false, 'CONSENT_REQUIRED'],
        'only two of three agreed' => [['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'ko', 'consent_identity' => true, 'consent_account_sharing' => true], false, 'CONSENT_REQUIRED'],
        'one consent explicitly false' => [['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'ko', 'consent_identity' => true, 'consent_account_sharing' => false, 'consent_payment_refund' => true], false, 'CONSENT_REQUIRED'],
        'one consent is the string "false"' => [['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'ko', 'consent_identity' => 'false', 'consent_account_sharing' => true, 'consent_payment_refund' => true], false, 'CONSENT_REQUIRED'],
        'one consent is 0' => [['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'ko', 'consent_identity' => 0, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false, 'CONSENT_REQUIRED'],
        'stale consent_version' => [['consent_version' => 'not-the-current-version', 'consent_locale' => 'ko', 'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false, 'CONSENT_VERSION_STALE'],
        'missing consent_version' => [['consent_locale' => 'ko', 'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false, 'CONSENT_REQUIRED'],
        'unsupported consent_locale' => [['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'de', 'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false, 'CONSENT_LOCALE_UNSUPPORTED'],
    ];
    foreach ($cases as $label => [$extra, $withConsent, $code]) {
        [$st, $user, $j, $raw] = $register($extra, $withConsent);
        t_check("C2 refused: $label -> 422, no user row, error code $code",
            $st === 422 && $user === null && ($j['error'] ?? null) === $code, "http=$st error=" . json_encode($j['error'] ?? null));
    }

    // ---- C3 happy path: one user, exactly one consent row, all three flags true
    [$st, $u, , $raw, $email] = $register();
    t_check('C3 all three consents -> signup succeeds', $st === 200 && $u !== null, "http=$st $raw");
    $consent = $u ? DB::table('v2_user_consent')->where('user_id', $u->id)->get() : collect();
    $row = $consent->first();
    t_check('C3 exactly one consent row recorded for the new user', $consent->count() === 1);
    t_check('C3 consent row records version, normalized locale, three true flags and an integer agreed_at',
        $row
        && $row->consent_version === ConsentService::currentVersion()
        && $row->locale === 'ko'
        && (int) $row->agreed_identity === 1 && (int) $row->agreed_account_sharing === 1 && (int) $row->agreed_payment_refund === 1
        && is_numeric($row->agreed_at) && (int) $row->agreed_at > 1700000000 && abs((int) $row->agreed_at - time()) < 300
        && (int) $row->created_at === (int) $row->agreed_at,
        $row ? json_encode(['v' => $row->consent_version, 'l' => $row->locale, 'at' => $row->agreed_at]) : 'no row');
    // locale tag normalization: ko-KR must be stored as ko
    [$stN, $uN] = $register(['consent_version' => ConsentService::currentVersion(), 'consent_locale' => 'ja-JP', 'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false);
    t_check('C3b regional locale tag ja-JP is accepted and stored as ja',
        $stN === 200 && $uN && DB::table('v2_user_consent')->where('user_id', $uN->id)->value('locale') === 'ja', "http=$stN");

    // ---- C4 the gate runs BEFORE any side effect: a refused signup consumes no invite code
    $inviter = $u;
    $code = null;
    if ($inviter) {
        // the app never mass-assigns InviteCode (InviteController::save uses new + attribute assignment),
        // so the model is totally guarded on purpose -- the fixture matches that pattern instead of widening it
        $code = new InviteCode();
        $code->user_id = $inviter->id;
        $code->code = 'RBCONSENT' . Str::upper(Str::random(8));
        $code->status = 0;
        $code->pv = 0;
        if (!$code->save()) $code = null;
    }
    if ($code) {
        [$stNo] = $register(['invite_code' => $code->code], false);
        $stillUnused = (int) InviteCode::find($code->id)->status === 0;
        t_check('C4 consent-refused signup consumes no invite code (gate precedes side effects)', $stNo === 422 && $stillUnused, "http=$stNo status=" . (int) InviteCode::find($code->id)->status);
        [$stYes, $uYes] = $register(['invite_code' => $code->code]);
        t_check('C4b the same code still works for a consenting signup', $stYes === 200 && $uYes !== null, "http=$stYes");
    } else {
        t_check('C4 invite-code fixture created', false, 'could not create invite code');
    }

    // ---- C5 no user row can exist without consent evidence
    $orphans = DB::table('v2_user')
        ->leftJoin('v2_user_consent', 'v2_user.id', '=', 'v2_user_consent.user_id')
        ->whereNull('v2_user_consent.user_id')
        ->where('v2_user.email', 'like', 'rollback-test-consent-%')
        ->count();
    t_check('C5 no test user was created without a consent row', $orphans === 0, "orphans=$orphans");

    // ---- C6 validateTerms rejects non-compliant admin text and accepts the built-in default
    [, $errDefault] = ConsentService::validateTerms(ConsentService::defaultTerms());
    t_check('C6 built-in default terms pass their own validator', $errDefault === [], json_encode($errDefault));
    $mutate = function (callable $fn) { $t = ConsentService::defaultTerms(); $fn($t); return ConsentService::validateTerms($t)[1]; };
    t_check('C6a missing locale is rejected', $mutate(function (&$t) { unset($t['ja']); }) !== []);
    t_check('C6b missing section is rejected', $mutate(function (&$t) { unset($t['ko']['account_sharing']); }) !== []);
    t_check('C6c empty checkbox label is rejected', $mutate(function (&$t) { $t['en']['identity']['checkbox_label'] = '   '; }) !== []);
    t_check('C6d dropping the statutory refund clause is rejected', $mutate(function (&$t) { $t['ko']['payment_refund']['warnings'] = ['환불 정책은 별도 안내합니다.']; $t['ko']['payment_refund']['body'] = '결제 관련 안내입니다.'; }) !== []);
    t_check('C6e an unconditional "no refund" sentence is rejected in all 4 locales', collect([
        'ko' => '환불 불가입니다', 'zh' => '一律不予退款', 'en' => 'No refunds.', 'ja' => '返金は不可です',
    ])->every(fn ($bad, $loc) => $mutate(function (&$t) use ($loc, $bad) { $t[$loc]['payment_refund']['warnings'][] = $bad; }) !== []));

    // ---- C7 admin API: bad payload writes nothing, good payload raises the version, old evidence survives
    $admin = new User();
    $admin->forceFill(['email' => 'rollback-test-consent-admin-' . Str::lower(Str::random(8)) . '@example.invalid',
        'password' => password_hash('Pw-' . Str::random(16), PASSWORD_DEFAULT), 'uuid' => Str::uuid(), 'token' => Str::random(32),
        'is_admin' => 1, 'remind_expire' => 0, 'remind_traffic' => 0])->save();
    $adminAuth = (new AuthService($admin))->generateAuthData()['auth_data'];
    $v0 = ConsentService::currentVersion();
    $settingsBefore = DB::table('v2_settings')->whereIn('name', [ConsentService::VERSION_KEY, ConsentService::TERMS_KEY])->count();
    $badTerms = ConsentService::defaultTerms();
    $badTerms['ko']['payment_refund']['warnings'][] = '환불 불가입니다';
    [$stBad] = $req('POST', $R['adminSave'], ['version' => 'rc-test-1', 'terms' => $badTerms], $adminAuth);
    t_check('C7 admin save with an unconditional no-refund sentence -> 422, nothing written',
        $stBad === 422 && ConsentService::currentVersion() === $v0
        && DB::table('v2_settings')->whereIn('name', [ConsentService::VERSION_KEY, ConsentService::TERMS_KEY])->count() === $settingsBefore, "http=$stBad");
    [$stVer] = $req('POST', $R['adminSave'], ['version' => 'bad version!', 'terms' => ConsentService::defaultTerms()], $adminAuth);
    t_check('C7b admin save with an invalid version string -> 422', $stVer === 422, "http=$stVer");
    [$stGood, $jGood] = $req('POST', $R['adminSave'], ['version' => 'rc-test-2', 'terms' => ConsentService::defaultTerms()], $adminAuth);
    t_check('C7c admin save with valid 4-locale terms -> 200 and the version is raised',
        $stGood === 200 && ConsentService::currentVersion() === 'rc-test-2' && ($jGood['data']['valid'] ?? false) === true, "http=$stGood v=" . ConsentService::currentVersion());
    t_check('C7d the guest API now serves the new version', ($req('GET', $R['terms'], ['locale' => 'en'])[1]['data']['version'] ?? null) === 'rc-test-2');
    // the already-recorded consent keeps its original version (no overwrite)
    t_check('C7e existing consent evidence still carries the version it was recorded under',
        $u && DB::table('v2_user_consent')->where('user_id', $u->id)->value('consent_version') === $v0);
    // signing up against the OLD version is now refused
    [$stStale, , $jStale] = $register(['consent_version' => $v0, 'consent_locale' => 'ko', 'consent_identity' => true, 'consent_account_sharing' => true, 'consent_payment_refund' => true], false);
    t_check('C7f a signup against the superseded version is refused with CONSENT_VERSION_STALE',
        $stStale === 422 && ($jStale['error'] ?? null) === 'CONSENT_VERSION_STALE', "http=$stStale");
    // a second signup under the new version records a SECOND row for that version
    [$stNew, $uNew] = $register();
    t_check('C7g a signup under the new version records it', $stNew === 200 && $uNew
        && DB::table('v2_user_consent')->where('user_id', $uNew->id)->value('consent_version') === 'rc-test-2', "http=$stNew");
    [$stHist, $jHist] = $req('GET', $R['adminHistory'], ['user_id' => $uNew ? $uNew->id : 0], $adminAuth);
    t_check('C7h admin can read a user consent history', $stHist === 200 && count($jHist['data'] ?? []) === 1, "http=$stHist");
    [$stNoAuth] = $req('GET', $R['adminFetch'], []);
    t_check('C7i admin consentTerms requires admin auth', $stNoAuth !== 200, "http=$stNoAuth");

    DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    t_check('unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// ---- post-rollback: nothing outside the transaction changed
foreach ($before as $t => $sig) {
    t_check("rollback clean: $t unchanged", $rows($t) === $sig, "before=$sig after=" . $rows($t));
}
t_check('rollback clean: v2_user count unchanged', DB::table('v2_user')->count() === $usersBefore, "before=$usersBefore after=" . DB::table('v2_user')->count());
t_check('rollback clean: no rollback-test-consent user survived', DB::table('v2_user')->where('email', 'like', 'rollback-test-consent-%')->count() === 0);

echo $fails ? "RESULT FAIL ($fails)\n" : "RESULT PASS\n";
exit($fails ? 1 : 0);
