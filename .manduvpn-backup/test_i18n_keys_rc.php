<?php
// ManduVPN RAO-30 regression: every user-facing message key the backend calls must exist in all four
// translated dictionaries, and the Korean rendering of the VPN/profile messages must not change.
//
// Why this check exists: before RAO-30 the backend had two ways to produce a user-facing error --
// __() with an English source key (translatable) and a raw literal in the source file (not
// translatable). Converting a raw Korean literal to __() keeps Korean output ONLY if the ko-KR key
// carries the exact same string; if the key is missing, Laravel falls back to the key itself and the
// Korean user silently starts seeing English. That failure is invisible to a status-code check, so
// it needs its own assertion.
//
// Run: docker exec -w /www <container> php /www/.manduvpn-backup/test_i18n_keys_rc.php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }

$langs = ['ko-KR', 'ja-JP', 'zh-CN', 'zh-TW'];
$dicts = [];
foreach ($langs as $l) {
    $p = '/www/resources/lang/' . $l . '.json';
    $dicts[$l] = is_file($p) ? json_decode(file_get_contents($p), true) : null;
    t_check("dictionary loads: $l", is_array($dicts[$l]), is_array($dicts[$l]) ? count($dicts[$l]) . ' keys' : 'missing or invalid JSON');
}

// Collect every __() key the application calls, in BOTH quote styles.
//
// This comment used to say single-quoted was "the form the codebase uses" and that a double-quoted
// key "would be a new pattern worth noticing". That was false when it was written: Handler.php:98
// was already double-quoted, and is the one key that cannot be single-quoted, because the sentence
// contains apostrophes. The collector matched neither it nor any future double-quoted key, so the
// string at the centre of the incident was silently absent from all four coverage assertions.
// A variable key is still not collected; that remains a pattern worth noticing, and there are none.
$keys = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/www/app'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    // BOTH quote styles. Handler.php:98 is double-quoted and CANNOT be single-quoted -- its
    // sentence contains "we've" and "we're" -- so a single-quote-only collector excludes the one
    // string this whole incident was about from every assertion below.
    if (preg_match_all('/__\\(\\s*(?:\\x27([^\\x27]+)\\x27|"([^"]+)")/', file_get_contents($f->getPathname()), $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $k = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
            if ($k !== '') $keys[$k] = true;
        }
    }
}
$keys = array_keys($keys);
t_check('found __() keys in app/', count($keys) > 100, count($keys) . ' distinct keys');

// The key itself is the English source string, so a key containing CJK means the English source is
// missing -- and every locale without that key then renders Chinese/Korean text.
$cjk = array_values(array_filter($keys, fn ($k) => preg_match('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7A3}]/u', $k)));
t_check('no __() key is written in CJK (the key must be the English source)', $cjk === [], implode(' | ', $cjk));

foreach ($langs as $l) {
    if (!is_array($dicts[$l])) continue;
    $missing = array_values(array_filter($keys, fn ($k) => !array_key_exists($k, $dicts[$l])));
    t_check("every called key is translated in $l", $missing === [], count($missing) . ' missing: ' . implode(' | ', array_slice($missing, 0, 6)));
}

// Korean must still read exactly as it did when these messages were raw Korean literals in the
// controllers (RAO-30 B-2). Any drift here is a user-visible regression for the default locale.
app()->setLocale('ko-KR');
$frozen = [
    'Active subscription required to issue VPN connection info' => '이용 중인 구독이 없어 VPN 접속 정보를 제공할 수 없습니다',
    'You are not allowed to connect to this server' => '해당 서버에 접속할 권한이 없습니다',
    'VPN connection info could not be issued' => 'VPN 접속 정보를 발급할 수 없습니다',
    'VPN connection info could not be read' => 'VPN 접속 정보를 읽을 수 없습니다',
    'Active subscription required to read VPN entitlement' => '이용 중인 구독이 없어 VPN 권한을 조회할 수 없습니다',
    'Active subscription required to issue a VPN session' => '이용 중인 구독이 없어 VPN 세션을 발급할 수 없습니다',
    'Unsupported protocol' => '지원하지 않는 프로토콜입니다',
    'Unsupported routing mode' => '지원하지 않는 라우팅 모드입니다',
    'Your VPN connection info has expired, please connect again' => 'VPN 접속 정보가 만료되었습니다. 다시 연결해 주세요',
    'Session not found' => '세션을 찾을 수 없습니다',
    'Failed to create the VPN account' => 'VPN 계정 생성에 실패했습니다',
    'VPN account not found' => 'VPN 계정이 없습니다',
];
foreach ($frozen as $k => $expected) {
    $got = __($k);
    t_check('ko-KR unchanged: ' . $k, $got === $expected, $got === $expected ? '' : "got [$got]");
}

// D-3. Only ko-KR had its values pinned. ja-JP and zh-TW had none and zh-CN had a single
// "not English" check, so a wrong translation passed on 124 of 136 keys -- and the two locales
// with no value assertion at all were zh-CN and zh-TW, which is the surface this card is fixing.
// Measuring a surface with a ruler that has no marks on that surface is not measuring it.
//
// These expected strings were generated from the dictionaries as they stand at this commit, so
// they are a FROZEN BASELINE against drift. They are NOT a review: nobody has checked that the
// Japanese or Chinese wording is good, only that it stops changing without someone noticing.
app()->setLocale('ja-JP');
$frozen = [
    'Active subscription required to issue VPN connection info' => '有効なサブスクリプションがないため VPN 接続情報を発行できません',
    'You are not allowed to connect to this server' => 'このサーバーに接続する権限がありません',
    'VPN connection info could not be issued' => 'VPN 接続情報を発行できません',
    'VPN connection info could not be read' => 'VPN 接続情報を読み取れません',
    'Active subscription required to read VPN entitlement' => '有効なサブスクリプションがないため VPN 権限を照会できません',
    'Active subscription required to issue a VPN session' => '有効なサブスクリプションがないため VPN セッションを発行できません',
    'Unsupported protocol' => 'サポートされていないプロトコルです',
    'Unsupported routing mode' => 'サポートされていないルーティングモードです',
    'Your VPN connection info has expired, please connect again' => 'VPN 接続情報の有効期限が切れました。再接続してください',
    'Session not found' => 'セッションが見つかりません',
    'Failed to create the VPN account' => 'VPN アカウントの作成に失敗しました',
    'VPN account not found' => 'VPN アカウントがありません',
    'Filtered out :count routes' => ':count 件の回線を除外しました',
    'Subscription expires: :date' => 'プラン有効期限: :date',
    'Days until next reset: :days' => '次回リセットまで :days 日',
    'Remaining data: :traffic' => '残りデータ量: :traffic',
];
foreach ($frozen as $k => $expected) {
    $got = __($k);
    t_check('ja-JP unchanged: ' . $k, $got === $expected, $got === $expected ? '' : "got [$got]");
}

app()->setLocale('zh-CN');
$frozen = [
    'Active subscription required to issue VPN connection info' => '没有有效订阅，无法提供 VPN 连接信息',
    'You are not allowed to connect to this server' => '无权连接该节点',
    'VPN connection info could not be issued' => '无法签发 VPN 连接信息',
    'VPN connection info could not be read' => '无法读取 VPN 连接信息',
    'Active subscription required to read VPN entitlement' => '没有有效订阅，无法查询 VPN 权限',
    'Active subscription required to issue a VPN session' => '没有有效订阅，无法签发 VPN 会话',
    'Unsupported protocol' => '不支持的协议',
    'Unsupported routing mode' => '不支持的路由模式',
    'Your VPN connection info has expired, please connect again' => 'VPN 连接信息已过期，请重新连接',
    'Session not found' => '找不到会话',
    'Failed to create the VPN account' => 'VPN 账号创建失败',
    'VPN account not found' => 'VPN 账号不存在',
    'Filtered out :count routes' => '过滤掉:count条线路',
    'Subscription expires: :date' => '套餐到期：:date',
    'Days until next reset: :days' => '距离下次重置剩余：:days 天',
    'Remaining data: :traffic' => '剩余流量：:traffic',
];
foreach ($frozen as $k => $expected) {
    $got = __($k);
    t_check('zh-CN unchanged: ' . $k, $got === $expected, $got === $expected ? '' : "got [$got]");
}

app()->setLocale('zh-TW');
$frozen = [
    'Active subscription required to issue VPN connection info' => '沒有有效訂閱，無法提供 VPN 連線資訊',
    'You are not allowed to connect to this server' => '無權連線該節點',
    'VPN connection info could not be issued' => '無法簽發 VPN 連線資訊',
    'VPN connection info could not be read' => '無法讀取 VPN 連線資訊',
    'Active subscription required to read VPN entitlement' => '沒有有效訂閱，無法查詢 VPN 權限',
    'Active subscription required to issue a VPN session' => '沒有有效訂閱，無法簽發 VPN 會話',
    'Unsupported protocol' => '不支援的協定',
    'Unsupported routing mode' => '不支援的路由模式',
    'Your VPN connection info has expired, please connect again' => 'VPN 連線資訊已過期，請重新連線',
    'Session not found' => '找不到會話',
    'Failed to create the VPN account' => 'VPN 帳號建立失敗',
    'VPN account not found' => 'VPN 帳號不存在',
    'Filtered out :count routes' => '過濾掉:count條線路',
    'Subscription expires: :date' => '套餐到期：:date',
    'Days until next reset: :days' => '距離下次重置剩餘：:days 天',
    'Remaining data: :traffic' => '剩餘流量：:traffic',
];
foreach ($frozen as $k => $expected) {
    $got = __($k);
    t_check('zh-TW unchanged: ' . $k, $got === $expected, $got === $expected ? '' : "got [$got]");
}

// The three assertions below cover EVERY called key instead of a chosen few, without freezing
// wording, so a legitimate rewrite still passes while the three ways a dictionary goes wrong
// silently do not: the English source pasted in as the translation, an emptied value, and a
// value written in the wrong script. The last one is this incident's own failure mode -- a
// Korean user being shown Chinese -- so it gets a check of its own rather than a comment.
$scriptOf = [
    'ko-KR' => ['hangul', '/[\x{AC00}-\x{D7A3}]/u'],
    'ja-JP' => ['kana or han', '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u'],
    'zh-CN' => ['han', '/[\x{4E00}-\x{9FFF}]/u'],
    'zh-TW' => ['han', '/[\x{4E00}-\x{9FFF}]/u'],
];
foreach ($langs as $l) {
    if (!is_array($dicts[$l])) continue;
    $echoed = $blank = $wrongScript = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $dicts[$l])) continue;   // absence is the assertion above
        $v = $dicts[$l][$k];
        if ($v === $k) $echoed[] = $k;
        if (trim((string) $v) === '') $blank[] = $k;
        if (!preg_match($scriptOf[$l][1], (string) $v)) $wrongScript[] = $k;
    }
    t_check("$l does not echo the English source back", $echoed === [], count($echoed) . ' echoed: ' . implode(' | ', array_slice($echoed, 0, 4)));
    t_check("$l has no empty translation", $blank === [], count($blank) . ' empty: ' . implode(' | ', array_slice($blank, 0, 4)));
    t_check("$l is written in " . $scriptOf[$l][0], $wrongScript === [], count($wrongScript) . ' wrong script: ' . implode(' | ', array_slice($wrongScript, 0, 4)));
}

// The incident's own key, named explicitly. It is double-quoted at Handler.php:98 and was the one
// string the old collector could not see, so an assertion that merely counts keys would not prove
// the fix. This one names it.
$incidentKey = "Uh-oh, we've had some problems, we're working on it.";
t_check('the double-quoted Handler.php:98 key is now collected', in_array($incidentKey, $keys, true), 'collected keys: ' . count($keys));
foreach ($langs as $l) {
    if (!is_array($dicts[$l])) continue;
    t_check("the Handler.php:98 key is translated in $l", array_key_exists($incidentKey, $dicts[$l]), '');
}
app()->setLocale('ko-KR');


// A locale the dictionaries do not define must degrade to readable English, never to Chinese.
app()->setLocale('en-US');
t_check('en-US renders the English source', __('Unsupported protocol') === 'Unsupported protocol', __('Unsupported protocol'));
app()->setLocale('zh-CN');
t_check('zh-CN translates instead of echoing English', __('Unsupported protocol') !== 'Unsupported protocol', __('Unsupported protocol'));

// RAO-30 / session identifier: each auth-middleware throw must carry the right machine code as the
// THIRD ApiException argument, because that argument is what reaches the response `error` field
// (ApiException::errors() -> Handler::render -> fail(..., $errors) -> jsonResponse 'error').
//
// Per-THROW, not per-file: Staff.php has two causes behind one guard and they are NOT the same
// failure. decryptAuthData() returning null is an expired or forged token; !is_staff is a permission
// refusal with a perfectly live session. A client that clears the stored session on SESSION_EXPIRED
// would sign out a logged-in non-staff user, so the second one must never report the session code.
// Asserted statically because no status-code or translation check can see a wrong identifier.
$expected = [
    '/www/app/Http/Middleware/User.php' => ['SESSION_EXPIRED' => 1, 'STAFF_PERMISSION_REQUIRED' => 0],
    '/www/app/Http/Middleware/Admin.php' => ['SESSION_EXPIRED' => 1, 'STAFF_PERMISSION_REQUIRED' => 0],
    // :21 no token + :28 decrypt failure = session; :29 !is_staff = permission.
    '/www/app/Http/Middleware/Staff.php' => ['SESSION_EXPIRED' => 2, 'STAFF_PERMISSION_REQUIRED' => 1],
];
$sources = [];
foreach ($expected as $file => $counts) {
    $src = $sources[$file] = is_file($file) ? file_get_contents($file) : '';
    foreach ($counts as $code => $n) {
        $pattern = "/ApiException\(\s*__\('[^']+'\)\s*,\s*403\s*,\s*'" . $code . "'\s*\)/";
        $found = preg_match_all($pattern, $src);
        t_check("$code x$n in " . basename($file), $found === $n, "found $found, expected $n");
    }
    // No site may still throw the old raw literal: that string is what the web matched on, and one
    // left behind means a single failure ships two different payloads.
    t_check('old raw literal gone from ' . basename($file), strpos($src, '未登录或登陆已过期') === false);
}

// The retraction itself, pinned: the !is_staff guard must sit on its own line with the permission
// identifier. Written as one assertion on the guard rather than a count, because a future edit that
// merges the two guards back together would keep every count above correct.
$staff = $sources['/www/app/Http/Middleware/Staff.php'];
t_check(
    '!is_staff throws the permission code, not the session code',
    (bool) preg_match("/if \(!\\\$user\['is_staff'\]\) throw new ApiException\(__\('Staff permission required'\), 403, 'STAFF_PERMISSION_REQUIRED'\);/", $staff),
    'the !is_staff guard must be its own statement carrying STAFF_PERMISSION_REQUIRED'
);
t_check(
    'no guard mixes !$user with !is_staff behind one identifier',
    strpos($staff, "!\$user || !\$user['is_staff']") === false,
    'decrypt failure and permission refusal must stay separate throws'
);

// The `error` field is a STRING, and the web depends on that: it compares payload.error to an
// identifier. ApiException names its third parameter $errors and Handler passes it straight through,
// so an array there is syntactically possible -- it would make the web's comparison silently false.
// This asserts the shape at every producer instead of trusting a one-off measurement.
// Boundary: this catches a literal array and a validator bag in the THIRD argument position. It does
// not follow a variable, so `fail([c,m], null, $x)` where $x later becomes an array is out of reach --
// a one-off source read is what covers that, and 21 of 21 producers at 3a578b7 pass a quoted literal.
// Position-aware on purpose: an argument-blind pattern flags the DATA argument of
// `fail([code,msg], ['a'=>1])`, which is 4 false positives on this tree.
$thirdArgs = [];
$patterns = [
    '/fail\(\s*\[[^\]]*\]\s*,[^,;]*,\s*(\[|array\(|\$[A-Za-z_]+->(?:errors|all)\()/s',
    '/new ApiException\(\s*(?:__\([^)]*\)|\'[^\']*\'|"[^"]*")\s*,[^,;]*,\s*(\[|array\(|\$[A-Za-z_]+->(?:errors|all)\()/s',
];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/www/app'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    foreach ($patterns as $pat) {
        if (preg_match_all($pat, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $thirdArgs[] = basename($f->getPathname()) . ':' . (substr_count(substr($src, 0, $hit[1]), "\n") + 1);
            }
        }
    }
}
t_check('no producer puts an array in the `error` field', $thirdArgs === [], implode(' | ', $thirdArgs));

// The subscription profile's info rows are `'name' =>` values, not error messages, so no assertion
// above looks at them -- yet they reach a VPN user's server list directly. Four were Chinese
// literals (`套餐到期：...`); a status check cannot see that and the __()-key check cannot
// either, because an untranslated literal produces no key to be missing. So the shape is asserted
// at the source: every label in setSubscribeInfoToServers goes through __().
$clientSrc = file_get_contents('/www/app/Http/Controllers/V1/Client/ClientController.php');
$labelBlock = '';
if (preg_match('/function setSubscribeInfoToServers.*?\n    \}/s', $clientSrc, $m)) $labelBlock = $m[0];
t_check('subscription label block found', $labelBlock !== '', strlen($labelBlock) . ' bytes');
preg_match_all("/'name'\s*=>\s*([^,\n]+)/", $labelBlock, $lm);
$rawLabels = array_values(array_filter($lm[1], fn ($v) => !str_starts_with(trim($v), '__(')));
t_check('every subscription label goes through __()', $rawLabels === [], implode(' | ', $rawLabels));
$cjkLabels = array_values(array_filter($lm[1], fn ($v) => preg_match('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7A3}]/u', $v)));
t_check('no subscription label holds a CJK literal', $cjkLabels === [], implode(' | ', $cjkLabels));
t_check('all four subscription labels are present', count($lm[1]) === 4, count($lm[1]) . ' labels');


echo "\nRESULT " . ($fails === 0 ? 'PASS' : "FAIL ($fails)") . "\n";
exit($fails === 0 ? 0 : 1);
