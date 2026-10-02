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

// Collect every __('...') key the application calls. Single-quoted first argument only -- that is the
// form the codebase uses, and a double-quoted or variable key would be a new pattern worth noticing.
$keys = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/www/app'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (preg_match_all("/__\\(\\s*'([^']+)'/", file_get_contents($f->getPathname()), $m)) {
        foreach ($m[1] as $k) $keys[$k] = true;
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

// A locale the dictionaries do not define must degrade to readable English, never to Chinese.
app()->setLocale('en-US');
t_check('en-US renders the English source', __('Unsupported protocol') === 'Unsupported protocol', __('Unsupported protocol'));
app()->setLocale('zh-CN');
t_check('zh-CN translates instead of echoing English', __('Unsupported protocol') !== 'Unsupported protocol', __('Unsupported protocol'));

// RAO-30 / session identifier: the four auth-middleware throws must carry the machine code as the
// THIRD ApiException argument, because that argument is what reaches the response `error` field
// (ApiException::errors() -> Handler::render -> fail(..., $errors) -> jsonResponse 'error').
// Asserted statically on purpose: the web clears a stored session off this identifier, so a site that
// silently loses its third argument leaves an expired user on a screen that loads nothing -- a
// behaviour failure no status-code or translation check can see.
$sites = [
    '/www/app/Http/Middleware/User.php' => 1,
    '/www/app/Http/Middleware/Admin.php' => 1,
    '/www/app/Http/Middleware/Staff.php' => 2,
];
$pattern = "/ApiException\\(\\s*__\\('Not logged in or the login has expired'\\)\\s*,\\s*403\\s*,\\s*'SESSION_EXPIRED'\\s*\\)/";
foreach ($sites as $file => $expected) {
    $src = is_file($file) ? file_get_contents($file) : '';
    $n = preg_match_all($pattern, $src);
    t_check('SESSION_EXPIRED carried in ' . basename($file), $n === $expected, "found $n, expected $expected");
    // No site may still throw the old raw literal: that string is what the web matched on, and one
    // left behind means a single failure ships two different payloads.
    t_check('old raw literal gone from ' . basename($file), strpos($src, '未登录或登陆已过期') === false);
}

echo "\nRESULT " . ($fails === 0 ? 'PASS' : "FAIL ($fails)") . "\n";
exit($fails === 0 ? 0 : 1);
