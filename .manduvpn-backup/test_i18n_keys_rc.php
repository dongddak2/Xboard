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
// One overridable root, so the suite can run somewhere other than the container it was written
// for. LEAD asked whether this was cheap; it is one line plus a mechanical substitution, and it is
// what turns QA's labelled replica measurement into an actual run of THIS file. Default is
// unchanged, so nothing about the container invocation changes.
$ROOT = getenv('MANDUVPN_TEST_ROOT') ?: '/www';
require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fails = 0;
function t_check($n, $ok, $d = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS ' : 'FAIL ') . "$n" . ($d !== '' ? " -- $d" : '') . "\n"; }

$langs = ['ko-KR', 'ja-JP', 'zh-CN', 'zh-TW'];
$dicts = [];
foreach ($langs as $l) {
    $p = $ROOT . '/resources/lang/' . $l . '.json';
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
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app'));
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
$scriptOfViews = [
    'ko-KR' => '/[\x{AC00}-\x{D7A3}]/u',
    'ja-JP' => '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u',
    'zh-CN' => '/[\x{4E00}-\x{9FFF}]/u',
    'zh-TW' => '/[\x{4E00}-\x{9FFF}]/u',
];

// LEAD 2026-10-05: per-locale key coverage, over EVERY tree that calls __(), not only app/.
//
// The assertion directly above has measured per-locale coverage since the collector was
// introduced, and it reports 0 missing in all four locales. What was never asserted is the
// collector's TREE. It walks /www/app and nothing else, so 18 __() keys in resources/views sat
// outside every assertion in this file -- not failing, not passing, unmeasured. One of them,
// 'Server Error' in errors/500.blade.php, is in no dictionary at all, including en-US. That is
// this card's own failure mode, found in the one place the ruler did not reach: a Korean user
// gets the English word on the 500 page title and nothing reports it.
//
// A tree the collector does not walk is not a passing surface. The scope of a measurement has to
// be asserted alongside its result, or the result reads as broader than it is.
// QA DEMONSTRATED, rather than assumed, what the old `continue` did here: with a tree absent,
// the per-locale coverage assertion below reports PASS with 0 missing in all four locales, because
// "every called key is translated" is vacuously true of an empty key set. Two different causes --
// a real finding and an unresolved path -- produced one colour, and the assertion that went
// falsely GREEN was the ordered one. A missing collection tree is an assertion failure.
$viewKeys = [];
$missingTrees = [];
foreach ([$ROOT . '/resources/views', $ROOT . '/routes'] as $tree) {
    if (!is_dir($tree)) { $missingTrees[] = $tree; continue; }
    $itv = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree));
    foreach ($itv as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        if (preg_match_all('/__\(\s*(?:\x27([^\x27]+)\x27|"([^"]+)")/', file_get_contents($f->getPathname()), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $k = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
                if ($k !== '' && !in_array($k, $keys, true)) $viewKeys[$k] = true;
            }
        }
    }
}
$viewKeys = array_keys($viewKeys);
t_check('[ENV] every view/route collection tree resolves', $missingTrees === [], 'unresolved: ' . implode(' | ', $missingTrees));
t_check('collector reaches the view/route trees too', count($viewKeys) > 0, count($viewKeys) . ' distinct keys called outside app/');

// The assertion LEAD ordered: per-locale coverage, FAIL on MISS, over the whole called set.
// The miss list is printed IN FULL and not sliced. The assertion above slices to 6, which is how
// a list of names quietly becomes a count -- and the names are the part that can be acted on.
$allKeys = array_values(array_unique(array_merge($keys, $viewKeys)));
foreach ($langs as $l) {
    if (!is_array($dicts[$l])) continue;
    $missing = array_values(array_filter($allKeys, fn ($k) => !array_key_exists($k, $dicts[$l])));
    t_check("every called key is translated in $l -- app/ AND views", $missing === [], count($missing) . ' of ' . count($allKeys) . ' missing: ' . implode(' | ', $missing));
}

// The three silent-failure checks further down run over $keys, so the view-only keys had never
// been checked for English backflow, emptiness or wrong script either. Measured here: 17 of the
// 18 are translated correctly in all four locales; only the absent one is absent.
foreach ($langs as $l) {
    if (!is_array($dicts[$l])) continue;
    $vEchoed = $vBlank = $vWrong = [];
    foreach ($viewKeys as $k) {
        if (!array_key_exists($k, $dicts[$l])) continue;   // absence is the assertion above
        $v = (string) $dicts[$l][$k];
        if ($v === $k) $vEchoed[] = $k;
        if (trim($v) === '') $vBlank[] = $k;
        if (!preg_match($scriptOfViews[$l], $v)) $vWrong[] = $k;
    }
    t_check("view keys do not echo English back in $l", $vEchoed === [], implode(' | ', $vEchoed));
    t_check("view keys are non-empty in $l", $vBlank === [], implode(' | ', $vBlank));
    t_check("view keys are in the right script in $l", $vWrong === [], implode(' | ', $vWrong));
}

// Correction, in place: the app/ collector's comment says a variable key "remains a pattern worth
// noticing, and there are none". That was true of app/ and was measured over app/ only -- the
// sentence reads as global. There is one, in the tree the collector did not walk:
// errors/500.blade.php passes $exception->getMessage() to the translator as a KEY, so the key is
// not knowable until runtime and NO key-list assertion can cover it.
//
// The boundary that used to be accepted here is CLOSED. It read: "the message of any 500 is
// looked up as a translation key", owner RAO-30 card, and it named 500.blade.php:5 as the one
// variable-key call. RAO-46 removed that call -- the view now passes a literal -- so the
// inventory is empty, and this assertion pins the empty set.
//
// It is still worth asserting, and more so now: the design question got an answer, so a
// variable key reappearing anywhere in app/, views or routes is a regression against a decision
// rather than an open question. Keeping the assertion at [] is what makes that visible.
// timepoint: emptied at the RAO-46 fix; previously pinned at 695fa41.
$varKeyCalls = [];
$missingVarTrees = [];
foreach ([$ROOT . '/app', $ROOT . '/resources/views', $ROOT . '/routes'] as $tree) {
    if (!is_dir($tree)) { $missingVarTrees[] = $tree; continue; }
    $itx = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree));
    foreach ($itx as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $lines = explode("\n", file_get_contents($f->getPathname()));
        foreach ($lines as $i => $line) {
            if (preg_match('/__\(\s*\$/', $line)) $varKeyCalls[] = basename($f->getPathname()) . ':' . ($i + 1);
        }
    }
}
sort($varKeyCalls);
t_check('[ENV] every variable-key scan tree resolves', $missingVarTrees === [], 'unresolved: ' . implode(' | ', $missingVarTrees));
t_check('no variable-key __() call exists', $varKeyCalls === [], implode(' | ', $varKeyCalls));

// LEAD 2026-10-05: en-US joins the MEASURED set.
//
// It was excluded from $langs because it is the English source, and that reasoning is sound for
// the script and echo checks -- key == value is the CORRECT answer for en-US, so the two checks
// that catch English backflow elsewhere would invert here. But 11 of its entries have a value
// that DIFFERS from its key, which means en-US is not merely a source listing: it is acting as a
// dictionary. A file that acts as a dictionary while sitting outside the measured set is the same
// hole as resources/views, so it gets the same remedy -- inside the fence, with the checks that
// actually apply to it, and not the two that would invert.
//
// Consequence recorded here and deliberately NOT fixed: for those 11, a key missing from ko-KR
// does not render the KEY, it renders en-US's VALUE, because the translator consults
// fallback_locale and config/app.php:116 sets that to 'en-US'. So "a miss renders the English
// key" is not a precise description of this failure mode for those 11 entries. Whether the 11
// should exist at all is not a test question.
$enPath = $ROOT . '/resources/lang/en-US.json';
$en = is_file($enPath) ? json_decode(file_get_contents($enPath), true) : null;
t_check('dictionary loads: en-US', is_array($en), is_array($en) ? count($en) . ' keys' : 'missing or invalid JSON');
if (is_array($en)) {
    // The sharp assertion first. 10 of the called keys are IDENTIFIER-style (update.*), where the
    // key is NOT the English text, so a locale missing one shows the user "update.migration_failed"
    // verbatim. Nothing asserted this before: the CJK-0 check cannot see a dotted ASCII key, and
    // the per-locale coverage checks treat it like any other key. These must exist in ALL FIVE.
    $identKeys = array_values(array_filter($allKeys, fn ($k) => (bool) preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $k)));
    t_check('identifier-style keys found', count($identKeys) > 0, count($identKeys) . ' keys where the key is not its own English text');
    foreach (array_merge($langs, ['en-US']) as $l) {
        $dict = $l === 'en-US' ? $en : $dicts[$l];
        if (!is_array($dict)) continue;
        $identMissing = array_values(array_filter($identKeys, fn ($k) => !array_key_exists($k, $dict)));
        t_check("every identifier-style key is defined in $l", $identMissing === [], implode(' | ', $identMissing));
    }

    // en-US is NOT in the coverage loop, by LEAD's narrowed ruling, and the reason is sound for
    // the English-sentence keys: 35 called keys are absent from it, and for English an absent key
    // renders the key, which IS the correct English. Putting those in the loop would be 35 red
    // lines with no user-visible harm, and a red assertion on an honest state gets switched off.
    //
    // DO NOT write "the suite catches en-US absences" anywhere: $langs has four entries and en-US
    // is outside that loop. "absent from all five dictionaries" is a true statement about the
    // FILES; the red lines will only ever name four.
    //
    // The identifier-style check above is deliberately NOT part of that exclusion, and the
    // measurement is why: all 11 en-US entries whose value differs from its key are CALLED, and
    // ten of them are those update.* keys. For them "a missing key renders correct English" is
    // false -- the key is an identifier, so the user would read "update.migration_failed". That is
    // the one thing en-US still has to be measured for.
    //
    // The 35 English-sentence keys absent from en-US are NOT being filled: LEAD's ruling is that
    // the cost is zero but so is the benefit, 35 duplicated lines would then have to be
    // maintained, and the dangerous case is already covered by the invariant assertion above.
    // Uniformity is not a goal in itself. So the number is pinned as an owned figure rather than
    // either filled or forgotten.
    //
    // ACCEPTED BOUNDARY (not a limitation to fix).
    // residual: 35 called keys are absent from en-US, every one an English sentence, so an
    //   absence renders the key and the key is the correct English.
    // not covered: nothing, for the harmful case -- an identifier-style key among them fails the
    //   assertion above, where the key is not its own fallback.
    // owner: RAO-30 card, LEAD. accepted by: LEAD, 2026-10-06. timepoint: measured at f045898.
    // reconciliation: QA measured 36 of these at 2d75749 and this file pins 35 at f045898. Same
    //   set, two timepoints; the difference is the one key added in between, 'Server Error'.
    //   36 - 1 = 35, so the two figures are one measurement and not a contradiction.
    $enMissing = array_values(array_filter($allKeys, fn ($k) => !array_key_exists($k, $en)));
    t_check('the en-US absence count has not grown past the accepted 35', count($enMissing) <= 35, count($enMissing) . ' of ' . count($allKeys) . ' absent');

    $enBlank = [];
    foreach ($allKeys as $k) {
        if (array_key_exists($k, $en) && trim((string) $en[$k]) === '') $enBlank[] = $k;
    }
    t_check('en-US has no empty value', $enBlank === [], implode(' | ', $enBlank));

    // NOT an echo check: key == value is correct for the source locale. This counts the entries
    // where en-US overrides its own key, so a 12th appearing is visible rather than silent.
    //
    // There are two elevens in this file and they are DISJOINT -- intersection zero, measured, not
    // assumed. Equal counts, opposite properties, so only the names tell them apart.
    //
    // THESE 11 are the self-overrides, and they are the ONLY keys for which a ko-KR miss emits
    // en-US's VALUE instead of the key, because the translator falls back to en-US. Every one of
    // them is CALLED. Named rather than counted, because the count cannot carry that property:
    //
    //   Uh-oh, we've had some problems, we're working on it.   <- the Handler.php:98 sentence
    //   update.already_latest        update.backup_failed      update.cache_clear_failed
    //   update.code_update_failed    update.failed             update.flag_create_failed
    //   update.local_newer           update.migration_failed   update.process_running
    //   update.success
    //
    // Ten of those are the identifier-style keys, which is why en-US cannot leave measurement
    // entirely. They stay as they are: deleting them would change user-visible English.
    //
    // The OTHER 11 is a different set -- en-US keys that nothing calls at all, every one of them
    // key == value, so none can ever be a fallback target. Three are wording left behind by a
    // rename: "Plan cycle cannot be empty", "Wrong plan cycle" and "This payment cycle cannot be
    // purchased, please choose another cycle", against the called forms that say "period".
    $enOverrides = [];
    foreach ($en as $k => $v) {
        if ((string) $v !== (string) $k) $enOverrides[] = $k;
    }
    t_check('en-US overrides its own key in exactly the 11 known entries', count($enOverrides) === 11, count($enOverrides) . ' overrides');
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
    // D-5. These four were added to ja-JP/zh-CN/zh-TW but not here, and my report claimed all
    // four locales pinned the same 16 -- a symmetry I asserted without measuring it. QA measured
    // it: 12/16/16/16. The asymmetry mattered in the wrong direction, because ko-KR is the
    // default locale and the one this incident actually hit: a Korean wording change in these
    // four labels fired no assertion at all, while the same change in the other three did.
    // (A change to Chinese was still caught by the all-key wrong-script check; only drift
    // within Korean was invisible.) Values generated from ko-KR.json, not transcribed.
    'Filtered out :count routes' => '차단된 회선 :count개',
    'Subscription expires: :date' => '요금제 만료: :date',
    'Days until next reset: :days' => '다음 초기화까지 :days일',
    'Remaining data: :traffic' => '남은 데이터: :traffic',
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
// FROZEN BASELINE, NOT A REVIEW. owner: POST-RC native-speaker review card
// (assignee human, arranged by Owner). release: that review completes.
// Until then this guarantees only that the values cannot change silently.
//
// These expected strings were generated from the dictionaries as they stand at this commit.
// Nobody has checked that the Japanese or Chinese wording is good. The owner is named as a
// CARD and not as a person, because people change and the card remains.
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
//
// ACCEPTED BOUNDARY (not a limitation to fix).
// residual: 125 keys PER LOCALE can still be reworded within the correct script.
// basis: post-D collector, 141 collected keys (collector introduced at 7d0f3d3).
// timepoint: measured at 0e41e26, after D-5 made the pins symmetric at 16 per locale.
//   (before D-5 this figure was not uniform: ja-JP/zh-CN/zh-TW 125, ko-KR 129.)
// covered instead: English backflow / empty / wrong script, across all 141.
// accepted by: LEAD, 2026-10-05.
// reason: pinning all 141 would FAIL honest rewording, and an assertion that fails on
//   honest change gets deleted -- a switched-off guard is no guard.
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
    $ROOT . '/app/Http/Middleware/User.php' => ['SESSION_EXPIRED' => 1, 'STAFF_PERMISSION_REQUIRED' => 0],
    $ROOT . '/app/Http/Middleware/Admin.php' => ['SESSION_EXPIRED' => 1, 'STAFF_PERMISSION_REQUIRED' => 0],
    // :21 no token + :28 decrypt failure = session; :29 !is_staff = permission.
    $ROOT . '/app/Http/Middleware/Staff.php' => ['SESSION_EXPIRED' => 2, 'STAFF_PERMISSION_REQUIRED' => 1],
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
$staff = $sources[$ROOT . '/app/Http/Middleware/Staff.php'];
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
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app'));
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

// D-4. The other half of the collector's blind spot: every regex here used to stop at the first
// comma, so a CJK literal in the SECOND or later argument of __() was invisible --
// __('Ordered :n items', ['n' => '...']) passes while interpolating Chinese into the output.
// My own label assertion below had the same limit and I wrote it down instead of fixing it;
// writing a limitation into a comment does not make the check see any further.
//
// So stop splitting on commas. This walks a whole __( ... ) call by balancing parentheses while
// honouring quotes, which is what "read the arguments" actually requires.
function mv_calls(string $src, string $name): array {
    $out = [];
    $len = strlen($src);
    $off = 0;
    while (($pos = strpos($src, $name . '(', $off)) !== false) {
        $off = $pos + 1;
        $before = $pos > 0 ? $src[$pos - 1] : ' ';
        if (preg_match('/[A-Za-z0-9_$>]/', $before)) continue;   // ->__( or foo__( is not __(
        $depth = 0; $quote = null;
        for ($j = $pos + strlen($name); $j < $len; $j++) {
            $c = $src[$j];
            if ($quote !== null) {
                if ($c === '\\') { $j++; continue; }
                if ($c === $quote) $quote = null;
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; continue; }
            if ($c === '(') { $depth++; continue; }
            if ($c === ')') {
                $depth--;
                if ($depth === 0) { $out[] = [$pos, substr($src, $pos, $j - $pos + 1)]; break; }
            }
        }
    }
    return $out;
}

// Split a call's arguments on TOP-LEVEL commas only, so a nested array stays whole.
function mv_args(string $call): array {
    $inner = substr($call, strpos($call, '(') + 1, -1);
    $args = []; $cur = ''; $depth = 0; $quote = null;
    for ($i = 0; $i < strlen($inner); $i++) {
        $c = $inner[$i];
        if ($quote !== null) {
            $cur .= $c;
            if ($c === '\\') { $cur .= $inner[$i + 1] ?? ''; $i++; continue; }
            if ($c === $quote) $quote = null;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $cur .= $c; continue; }
        if ($c === '(' || $c === '[') $depth++;
        if ($c === ')' || $c === ']') $depth--;
        if ($c === ',' && $depth === 0) { $args[] = $cur; $cur = ''; continue; }
        $cur .= $c;
    }
    if ($cur !== '') $args[] = $cur;
    return $args;
}

$cjkArgs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $phpSrc = file_get_contents($f->getPathname());
    foreach (mv_calls($phpSrc, '__') as [$pos, $call]) {
        $args = mv_args($call);
        array_shift($args);                     // the first argument is the key, covered above
        foreach ($args as $a) {
            if (preg_match('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7A3}]/u', $a)) {
                $cjkArgs[] = basename($f->getPathname()) . ':' . (substr_count(substr($phpSrc, 0, $pos), "\n") + 1);
            }
        }
    }
}
t_check('no __() call interpolates a CJK literal through a later argument', $cjkArgs === [], implode(' | ', $cjkArgs));

// The subscription profile's info rows are `'name' =>` values, not error messages, so no assertion
// above looks at them -- yet they reach a VPN user's server list directly. Four were Chinese
// literals (`套餐到期：...`); a status check cannot see that and the __()-key check cannot
// either, because an untranslated literal produces no key to be missing. So the shape is asserted
// at the source: every label in setSubscribeInfoToServers goes through __().
$clientSrc = file_get_contents($ROOT . '/app/Http/Controllers/V1/Client/ClientController.php');
$labelBlock = '';
if (preg_match('/function setSubscribeInfoToServers.*?\n    \}/s', $clientSrc, $m)) $labelBlock = $m[0];
t_check('subscription label block found', $labelBlock !== '', strlen($labelBlock) . ' bytes');
// Read each label as a WHOLE expression rather than up to the first comma. With
// __('x', ['y' => '...']) the truncated form showed only "__('x'", so a CJK string in the
// second argument passed this check -- the same blind spot as D-1, one layer down.
preg_match_all("/'name'\s*=>\s*/", $labelBlock, $lm, PREG_OFFSET_CAPTURE);
$labels = [];
foreach ($lm[0] as $hit) {
    $rest = substr($labelBlock, $hit[1] + strlen($hit[0]));
    if (str_starts_with($rest, '__(')) {
        $c = mv_calls($rest, '__');
        $labels[] = $c ? $c[0][1] : $rest;
    } else {
        $labels[] = rtrim(strtok($rest, "\n"), ',');
    }
}
$rawLabels = array_values(array_filter($labels, fn ($v) => !str_starts_with(trim($v), '__(')));
t_check('every subscription label goes through __()', $rawLabels === [], implode(' | ', $rawLabels));
$cjkLabels = array_values(array_filter($labels, fn ($v) => preg_match('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7A3}]/u', $v)));
t_check('no subscription label holds a CJK literal, in ANY argument', $cjkLabels === [], implode(' | ', $cjkLabels));
t_check('all four subscription labels are present', count($labels) === 4, count($labels) . ' labels');


// HOW TO READ A RED LINE. Two different causes used to produce one colour here, so the colour on
// its own is not evidence -- which assertion is red is.
//
//   a line marked [ENV] red  -> the ENVIRONMENT. A collection tree did not resolve, so the key set
//                               is short or empty. Fix the invocation, then re-read; every
//                               coverage line above it is meaningless until this one is green,
//                               because "every called key is translated" is vacuously TRUE of an
//                               empty key set. That is exactly how the ordered assertion once
//                               reported PASS with 0 missing in all four locales while the tree
//                               was absent.
//   any other line red       -> the FINDING. The code or the dictionaries are in the state the
//                               assertion names.
//
// So: read [ENV] first. If any [ENV] line is red, no conclusion about the dictionaries can be
// drawn from this run at all.
echo "\nRESULT " . ($fails === 0 ? 'PASS' : "FAIL ($fails)") . "\n";
echo "read [ENV] lines first: [ENV] red = the invocation, not the finding; a green coverage line under a red [ENV] line proves nothing\n";
exit($fails === 0 ? 0 : 1);
