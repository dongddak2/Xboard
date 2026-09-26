<?php

namespace App\Services;

use App\Models\UserConsent;
use Illuminate\Support\Facades\DB;

/**
 * ManduVPN RC: the three mandatory signup consents (RC_CONTRACTS.md §3).
 *
 * The BACKEND is the gate, not the frontend. validateRegistration() runs on every
 * POST /api/v1/passport/auth/register, so a client that skips the checkboxes and calls the API directly is
 * refused with 422 exactly like the web form would be. The three consents are three INDEPENDENT flags; there
 * is deliberately no "agree to everything" shortcut anywhere in this service.
 *
 * Terms text is admin-managed data, not translation files: the body/title/warnings/checkbox label for all four
 * locales live in one v2_settings row (manduvpn_consent_terms, JSON) and the version string in another
 * (manduvpn_consent_version), so wording and version can be changed in production without a deploy. When no row
 * exists the built-in DEFAULT_TERMS below are served, so a fresh install has a complete, valid 4-locale text.
 *
 * Refund wording is validated, not trusted: validateTerms() refuses to store a payment_refund section that drops
 * the statutory cooling-off / refund-obligation clause, and refuses an unconditional "no refund" phrase. That
 * check is the reason admins cannot accidentally publish a non-compliant term (RC_CONTRACTS.md §3.3).
 *
 * Evidence is recorded by record() inside the SAME transaction that creates the user (AuthController::register),
 * so a user row never exists without its consent row. A new consent_version inserts a new row - previous
 * consents are never overwritten (UNIQUE(user_id, consent_version) in the migration).
 */
class ConsentService
{
    public const VERSION_KEY = 'manduvpn_consent_version';
    public const TERMS_KEY = 'manduvpn_consent_terms';
    public const DEFAULT_VERSION = '2026-09-26.1';

    /** The three mandatory areas. Order is the display order and is part of the contract. */
    public const SECTIONS = ['identity', 'account_sharing', 'payment_refund'];

    /** Supported locales - fixed at four (RC_CONTRACTS.md §6). */
    public const LOCALES = ['ko', 'zh', 'en', 'ja'];

    /** Register parameter name -> section key. */
    public const PARAMS = [
        'consent_identity' => 'identity',
        'consent_account_sharing' => 'account_sharing',
        'consent_payment_refund' => 'payment_refund',
    ];

    /**
     * Phrases that would make the refund term unconditional. Refused unless the same locale's statutory
     * clause marker is also present, and the marker is required in every case (see REFUND_CLAUSE_MARKER).
     */
    public const REFUND_ABSOLUTE_PATTERNS = [
        'ko' => '/환불\s*(은|이)?\s*(불가|불가능|안\s*됩니다|되지\s*않습니다)/u',
        'zh' => '/(一律)?\s*(不予退款|不退款|不能退款|无法退款|概不退款)/u',
        'en' => '/\b(no|non)[\s-]?refunds?\b|\brefunds?\s+are\s+not\s+(possible|available|given)\b/i',
        'ja' => '/返金\s*(は|を)?\s*(不可|できません|いたしません|お受けできません)/u',
    ];

    /** The statutory cooling-off / refund-obligation clause must survive every admin edit. */
    public const REFUND_CLAUSE_MARKER = [
        'ko' => '청약철회',
        'zh' => '法律',
        'en' => 'required by law',
        'ja' => '法令',
    ];

    public static function currentVersion(): string
    {
        $v = DB::table('v2_settings')->where('name', self::VERSION_KEY)->value('value');
        $v = is_string($v) ? trim($v) : '';
        return $v === '' ? self::DEFAULT_VERSION : $v;
    }

    /**
     * Full terms for all locales: the stored admin text when it is present and valid, otherwise the built-in
     * defaults. Never returns a partially filled structure - callers can rely on all locales and sections.
     */
    public static function terms(): array
    {
        $raw = DB::table('v2_settings')->where('name', self::TERMS_KEY)->value('value');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                [$normalized, $errors] = self::validateTerms($decoded);
                if (!$errors) {
                    return $normalized;
                }
            }
        }
        return self::defaultTerms();
    }

    public static function normalizeLocale(?string $locale): ?string
    {
        $l = strtolower(trim((string) $locale));
        // Accept the common web/app tags for the four supported languages; store the short form.
        $map = [
            'ko' => 'ko', 'ko-kr' => 'ko', 'ko_kr' => 'ko',
            'zh' => 'zh', 'zh-cn' => 'zh', 'zh_cn' => 'zh', 'zh-hans' => 'zh',
            'en' => 'en', 'en-us' => 'en', 'en_us' => 'en', 'en-gb' => 'en',
            'ja' => 'ja', 'ja-jp' => 'ja', 'ja_jp' => 'ja',
        ];
        return $map[$l] ?? null;
    }

    /** Guest payload for GET /api/v1/guest/terms/current (RC_CONTRACTS.md §3.3). */
    public static function current(string $locale): array
    {
        $locale = self::normalizeLocale($locale) ?? 'ko';
        $terms = self::terms()[$locale];
        $sections = [];
        foreach (self::SECTIONS as $key) {
            $sections[] = [
                'key' => $key,
                'title' => $terms[$key]['title'],
                // Paragraphs are separated by "\n"; the frontend decides how to lay them out.
                'body' => $terms[$key]['body'],
                'warnings' => array_values($terms[$key]['warnings']),
                'checkbox_label' => $terms[$key]['checkbox_label'],
            ];
        }
        return [
            // `consent_version` is the contract name (RC_CONTRACTS.md §3): the client echoes this value back as
            // the `consent_version` parameter when it registers, so the key it reads and the key it sends match.
            // `version` is kept as an alias so nothing that already reads it breaks.
            'consent_version' => self::currentVersion(),
            'version' => self::currentVersion(),
            'locale' => $locale,
            // Unix INTEGER, the house convention for every v2_* timestamp - never an ISO string. Lets a client
            // cache the terms and re-fetch only when the text actually changed.
            'updated_at' => self::termsUpdatedAt(),
            // So the signup form can offer only the languages the backend can actually serve.
            'locales' => self::LOCALES,
            'sections' => $sections,
        ];
    }

    /**
     * When the terms or the version were last saved, as a unix integer (0 when neither row exists yet, i.e. the
     * built-in defaults are being served).
     *
     * v2_settings.updated_at is written by different paths in this codebase, so the stored value may be an
     * integer or a datetime string. Both are accepted and normalized here rather than assuming one shape.
     */
    public static function termsUpdatedAt(): int
    {
        $rows = DB::table('v2_settings')
            ->whereIn('name', [self::VERSION_KEY, self::TERMS_KEY])
            ->pluck('updated_at')
            ->all();
        $latest = 0;
        foreach ($rows as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            $ts = is_numeric($raw) ? (int) $raw : (int) strtotime((string) $raw);
            if ($ts > $latest) {
                $latest = $ts;
            }
        }
        return $latest;
    }

    /**
     * The signup gate. Returns a list of [machine code, translated message] pairs; an empty list means the
     * request may create a user. Called from AuthController::register BEFORE any user row exists.
     *
     * The machine code travels in the existing `error` field of the xboard response envelope (the error contract
     * shape is unchanged, RC_CONTRACTS.md §6), because the backend locale comes from the optional
     * `content-language` header: a client that does not send it would otherwise get the default-locale message.
     * Frontends localize off the code and may show the message as a fallback.
     */
    public static function validateRegistration(array $input): array
    {
        $errors = [];

        $version = isset($input['consent_version']) && is_string($input['consent_version'])
            ? trim($input['consent_version'])
            : '';
        if ($version === '') {
            $errors[] = ['CONSENT_REQUIRED', __('You must agree to the required terms to sign up')];
        } elseif ($version !== self::currentVersion()) {
            // Stale terms: the client agreed to text that is no longer current. Reload and agree again.
            $errors[] = ['CONSENT_VERSION_STALE', __('The terms have been updated, please review and agree again')];
        }

        $locale = self::normalizeLocale($input['consent_locale'] ?? null);
        if ($locale === null) {
            $errors[] = ['CONSENT_LOCALE_UNSUPPORTED', __('Unsupported terms language')];
        }

        foreach (self::PARAMS as $param => $_section) {
            if (self::isAgreed($input[$param] ?? null) !== true) {
                // Three separate mandatory consents: a missing OR false value refuses the signup.
                $errors[] = ['CONSENT_REQUIRED', __('You must agree to the required terms to sign up')];
                break;
            }
        }

        return $errors;
    }

    /** Strict truthiness: only real booleans / 1 / "1" / "true" count as agreement. */
    public static function isAgreed(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'TRUE'], true);
    }

    /**
     * Records the evidence row. MUST be called inside the signup transaction. Idempotent per
     * (user_id, consent_version) thanks to the unique index, so a retry cannot duplicate evidence.
     */
    public static function record(int $userId, string $version, string $locale, int $now): void
    {
        DB::table('v2_user_consent')->insertOrIgnore([
            'user_id' => $userId,
            'consent_version' => $version,
            'locale' => $locale,
            'agreed_identity' => 1,
            'agreed_account_sharing' => 1,
            'agreed_payment_refund' => 1,
            'agreed_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** Consent history for a user, newest first (admin read). */
    public static function historyFor(int $userId): array
    {
        return UserConsent::where('user_id', $userId)->orderByDesc('id')->get()->toArray();
    }

    /**
     * Validates an entire terms structure. Returns [normalized, errors]. Used by the admin save AND by
     * terms() before serving stored text, so invalid stored data can never reach a user.
     */
    public static function validateTerms(mixed $in): array
    {
        $errors = [];
        $out = [];
        if (!is_array($in)) {
            return [self::defaultTerms(), ['terms must be an object keyed by locale']];
        }
        foreach (self::LOCALES as $locale) {
            $localeIn = $in[$locale] ?? null;
            if (!is_array($localeIn)) {
                $errors[] = "missing terms for locale $locale";
                $out[$locale] = self::defaultTerms()[$locale];
                continue;
            }
            $out[$locale] = [];
            foreach (self::SECTIONS as $key) {
                $s = $localeIn[$key] ?? null;
                if (!is_array($s)) {
                    $errors[] = "missing section $key for locale $locale";
                    $out[$locale][$key] = self::defaultTerms()[$locale][$key];
                    continue;
                }
                $title = is_string($s['title'] ?? null) ? trim($s['title']) : '';
                $body = is_string($s['body'] ?? null) ? trim($s['body']) : '';
                $label = is_string($s['checkbox_label'] ?? null) ? trim($s['checkbox_label']) : '';
                $warnings = [];
                foreach ((array) ($s['warnings'] ?? []) as $w) {
                    if (is_string($w) && trim($w) !== '') {
                        $warnings[] = trim($w);
                    }
                }
                if ($title === '') {
                    $errors[] = "$locale.$key.title must not be empty";
                }
                if ($body === '') {
                    $errors[] = "$locale.$key.body must not be empty";
                }
                if ($label === '') {
                    $errors[] = "$locale.$key.checkbox_label must not be empty";
                }
                $out[$locale][$key] = [
                    'title' => $title,
                    'body' => $body,
                    'warnings' => $warnings,
                    'checkbox_label' => $label,
                ];
            }
            self::validateRefundWording($locale, $out[$locale]['payment_refund'], $errors);
        }
        return [$out, $errors];
    }

    /**
     * The payment_refund section must keep the statutory cooling-off / refund-obligation clause, and must not
     * state an unconditional refusal to refund. Both halves matter: an admin can neither delete the clause nor
     * add an absolute "no refund" sentence next to it.
     */
    private static function validateRefundWording(string $locale, array $section, array &$errors): void
    {
        $text = $section['title'] . "\n" . $section['body'] . "\n" . implode("\n", $section['warnings']);
        $marker = self::REFUND_CLAUSE_MARKER[$locale];
        if (mb_stripos($text, $marker) === false) {
            $errors[] = "$locale.payment_refund must keep the statutory refund-obligation clause (expected \"$marker\")";
        }
        if (preg_match(self::REFUND_ABSOLUTE_PATTERNS[$locale], $text)) {
            $errors[] = "$locale.payment_refund must not state an unconditional refusal to refund";
        }
    }

    /**
     * Built-in 4-locale default text. Content follows the Owner-confirmed spec in
     * docs\project\manduvpn-handoff.md ("회원가입 3단계 필수 동의, 확정 상세 2026-09-25").
     * Sentences that must be rendered in red by the frontend are in warnings[], not in body.
     */
    public static function defaultTerms(): array
    {
        return [
            'ko' => [
                'identity' => [
                    'title' => '실명 및 개인정보 정책',
                    'body' => "ManduVPN은 가입 시 실명을 요구합니다. 한국인은 한글 성명 전체, 외국인은 여권상 이름, 여권이 없는 중국 국적자는 居民身份证상 실명을 입력해 주세요.\n"
                        . "허위 이름, 닉네임, 타인의 이름은 사용할 수 없습니다.\n"
                        . "입력한 이름은 사용자 식별과 동명이인 구분 등 고지된 목적으로만 처리됩니다.",
                    'warnings' => ['허위 정보로 가입한 경우 계정이 제한되거나 삭제될 수 있습니다.'],
                    'checkbox_label' => '위 내용을 모두 읽고 확인했습니다.',
                ],
                'account_sharing' => [
                    'title' => '계정 공유 및 서비스 이용 정책',
                    'body' => "다기기 이용권은 한 사람이 여러 기기에서 사용하는 상품입니다. 여러 사람이 하나의 계정을 함께 쓰는 용도가 아닙니다.\n"
                        . "계정의 재판매, 양도, 제3자 이용은 금지됩니다.\n"
                        . "기기, IP, 지역, 동시접속 패턴으로 계정 공유가 의심될 수 있습니다. 다만 지역이나 IP 차이만으로 계정을 자동 삭제하지는 않습니다.",
                    'warnings' => ['계정 공유, 재판매, 제3자 제공이 확인되면 계정이 제한되거나 삭제될 수 있습니다.'],
                    'checkbox_label' => '위 내용을 모두 읽고 확인했습니다.',
                ],
                'payment_refund' => [
                    'title' => '결제·환불·불법 이용 및 책임 정책',
                    'body' => "ManduVPN은 중국 만리장성 방화벽(Great Firewall) 개발자 출신이 개발한 VPN 서비스입니다.\n"
                        . "2018년 서비스 시작 이후 현재까지 중국에서 단 한 번도 차단된 적 없이 서비스를 운영해 왔습니다.\n"
                        . "중국의 네트워크 품질은 지역, ISP, Wi-Fi, 모바일 데이터, 공유기, 기기, 설정에 따라 달라집니다. 연결이 불안정한 경우 고객의 개인 네트워크 환경에서 비롯될 수 있습니다.\n"
                        . "구매 전 FAQ(ISP, Wi-Fi, 모바일 데이터, 공유기, 기기, 네트워크 설정, 연결 문제 해결)를 확인해 주세요.\n"
                        . "불법적인 목적으로 서비스를 이용할 수 없습니다.",
                    'warnings' => [
                        'ManduVPN 서버 장애, 시스템 장애, 또는 중국 내 차단으로 정상적인 서비스 이용이 불가능한 경우에는 확인된 이용 불가 기간과 사정에 따라 이용기간을 보상·연장해 드립니다.',
                        '관련 법령상 청약철회 및 환불 의무가 있는 경우를 제외하고, ManduVPN 서버가 정상인데 사용자 기기·ISP·Wi-Fi·공유기·네트워크 환경 등 사용자 측 원인으로 이용이 어려운 경우에는 환불이 제한될 수 있습니다. FAQ를 확인하신 후 신중히 구매해 주세요.',
                        '중대한 불법 이용이 확인되면 서비스가 즉시 제한되거나 계정이 해지될 수 있습니다. 사용자의 행위로 ManduVPN 또는 제3자에게 손해가 발생한 경우 법적 책임을 질 수 있습니다.',
                        '피추천인의 불법 행위만으로 추천인 계정을 자동 정지하지는 않습니다. 다만 추천인이 가담하거나, 인지한 후에도 지원·방조하거나, 불법 이용자를 반복적으로 소개하거나, 불법 목적으로 추천 제도를 악용한 경우에는 추천인 계정도 정지·해지될 수 있습니다.',
                    ],
                    'checkbox_label' => '위 내용을 모두 읽고 확인했습니다.',
                ],
            ],
            'zh' => [
                'identity' => [
                    'title' => '实名与个人信息政策',
                    'body' => "注册 ManduVPN 需使用实名。韩国用户请填写完整的韩文姓名，外国用户请填写护照上的姓名，没有护照的中国籍用户请填写居民身份证上的实名。\n"
                        . "不得使用虚假姓名、昵称或他人姓名。\n"
                        . "所填姓名仅用于已告知的目的，例如识别用户与区分同名用户。",
                    'warnings' => ['使用虚假信息注册的账号可能被限制或删除。'],
                    'checkbox_label' => '我已阅读并确认以上全部内容。',
                ],
                'account_sharing' => [
                    'title' => '账号共享与服务使用政策',
                    'body' => "多设备套餐是供同一个人在多台设备上使用的产品，并非供多人共用同一个账号。\n"
                        . "禁止转售、转让账号或让第三方使用。\n"
                        . "我们可能通过设备、IP、地区与同时在线的模式判断是否存在账号共享嫌疑；但不会仅因地区或 IP 不同就自动删除账号。",
                    'warnings' => ['一经确认存在账号共享、转售或提供给第三方的情况，账号可能被限制或删除。'],
                    'checkbox_label' => '我已阅读并确认以上全部内容。',
                ],
                'payment_refund' => [
                    'title' => '支付、退款、违法使用与责任政策',
                    'body' => "ManduVPN 是由出身于中国防火长城（Great Firewall）研发背景的开发者打造的 VPN 服务。\n"
                        . "自 2018 年开始服务以来，至今在中国从未被封锁过。\n"
                        . "中国境内的网络质量会因地区、运营商、Wi-Fi、移动数据、路由器、设备与设置而不同。连接不稳定有可能源自用户自身的网络环境。\n"
                        . "购买前请先查看 FAQ（运营商、Wi-Fi、移动数据、路由器、设备、网络设置与连接问题排查）。\n"
                        . "不得将本服务用于任何违法目的。",
                    'warnings' => [
                        '若因 ManduVPN 服务器故障、系统故障或在中国境内被封锁而无法正常使用服务，我们将根据已确认的不可用时间与具体情况补偿或延长使用期限。',
                        '除相关法律规定的撤销购买（冷静期）与退款义务情形之外，若 ManduVPN 服务器正常，而问题源自用户的设备、运营商、Wi-Fi、路由器或网络环境等用户侧原因，退款可能受到限制。请先查看 FAQ 再谨慎购买。',
                        '一经确认存在重大违法使用行为，服务可能被立即限制或账号被终止。若用户行为给 ManduVPN 或第三方造成损害，用户可能承担法律责任。',
                        '不会仅因被推荐人的违法行为就自动停用推荐人账号。但若推荐人参与其中、在知情后仍提供协助或包庇、反复介绍违法使用者，或以违法目的滥用推荐制度，推荐人账号也可能被停用或终止。',
                    ],
                    'checkbox_label' => '我已阅读并确认以上全部内容。',
                ],
            ],
            'en' => [
                'identity' => [
                    'title' => 'Real Name and Personal Information Policy',
                    'body' => "ManduVPN requires your real name at signup. Korean users enter their full Korean name, non-Korean users the name on their passport, and Chinese nationals without a passport the real name on their 居民身份证 (resident identity card).\n"
                        . "False names, nicknames and other people's names may not be used.\n"
                        . "Your name is processed only for the purposes we have stated, such as identifying you and distinguishing users with the same name.",
                    'warnings' => ['An account registered with false information may be restricted or deleted.'],
                    'checkbox_label' => 'I have read and confirm all of the above.',
                ],
                'account_sharing' => [
                    'title' => 'Account Sharing and Service Use Policy',
                    'body' => "A multi-device plan lets one person use several devices. It is not a plan for several people sharing one account.\n"
                        . "Reselling, transferring or letting a third party use your account is prohibited.\n"
                        . "Device, IP, region and concurrent-connection patterns may raise a sharing suspicion. A difference in region or IP alone never causes an automatic deletion.",
                    'warnings' => ['If account sharing, resale or provision to a third party is confirmed, the account may be restricted or deleted.'],
                    'checkbox_label' => 'I have read and confirm all of the above.',
                ],
                'payment_refund' => [
                    'title' => 'Payment, Refund, Unlawful Use and Liability Policy',
                    'body' => "ManduVPN is a VPN service built by a developer who previously worked on China's Great Firewall.\n"
                        . "Since the service started in 2018 it has never once been blocked in China.\n"
                        . "Network quality inside China varies with your region, ISP, Wi-Fi, mobile data, router, device and settings. An unstable connection can originate in your own network environment.\n"
                        . "Please read the FAQ before buying (ISP, Wi-Fi, mobile data, router, device, network settings and connection troubleshooting).\n"
                        . "The service may not be used for any unlawful purpose.",
                    'warnings' => [
                        'If a ManduVPN server failure, a system failure, or blocking inside China makes normal use impossible, we compensate or extend your subscription period according to the confirmed period and circumstances of the outage.',
                        'Except where withdrawal of purchase (cooling-off) or a refund is required by law, a refund may be limited when the ManduVPN servers are working normally and the problem originates on your side - your device, ISP, Wi-Fi, router or network environment. Please read the FAQ and buy carefully.',
                        'If serious unlawful use is confirmed, the service may be restricted immediately and the account terminated. If your conduct causes damage to ManduVPN or a third party, you may be held legally liable.',
                        'A referrer account is never suspended merely because the person they referred acted unlawfully. It may however be suspended or terminated if the referrer took part, kept helping or covering for them after becoming aware, repeatedly referred unlawful users, or abused the referral programme for an unlawful purpose.',
                    ],
                    'checkbox_label' => 'I have read and confirm all of the above.',
                ],
            ],
            'ja' => [
                'identity' => [
                    'title' => '実名および個人情報に関するポリシー',
                    'body' => "ManduVPN のご登録には実名が必要です。韓国の方は韓国語の姓名すべて、外国籍の方はパスポート上の氏名、パスポートをお持ちでない中国籍の方は居民身份证に記載された実名をご入力ください。\n"
                        . "虚偽の氏名、ニックネーム、他人の氏名は使用できません。\n"
                        . "ご入力いただいた氏名は、利用者の識別や同姓同名の区別など、お知らせした目的の範囲でのみ取り扱います。",
                    'warnings' => ['虚偽の情報でご登録された場合、アカウントが制限または削除されることがあります。'],
                    'checkbox_label' => '上記の内容をすべて読み、確認しました。',
                ],
                'account_sharing' => [
                    'title' => 'アカウント共有およびサービス利用に関するポリシー',
                    'body' => "マルチデバイスプランは、お一人が複数の端末でご利用いただくための商品です。複数の方が一つのアカウントを共有するためのものではありません。\n"
                        . "アカウントの転売、譲渡、第三者による利用は禁止されています。\n"
                        . "端末、IP、地域、同時接続の傾向からアカウント共有が疑われる場合があります。ただし、地域や IP の違いだけを理由に自動で削除することはありません。",
                    'warnings' => ['アカウントの共有、転売、第三者への提供が確認された場合、アカウントが制限または削除されることがあります。'],
                    'checkbox_label' => '上記の内容をすべて読み、確認しました。',
                ],
                'payment_refund' => [
                    'title' => '決済・返金・違法利用および責任に関するポリシー',
                    'body' => "ManduVPN は、中国のグレートファイアウォール（Great Firewall）の開発に携わった経歴を持つ開発者が作った VPN サービスです。\n"
                        . "2018 年のサービス開始以来、中国で一度もブロックされることなく運営してきました。\n"
                        . "中国国内のネットワーク品質は、地域、ISP、Wi-Fi、モバイルデータ、ルーター、端末、設定によって異なります。接続が不安定な場合、お客様ご自身のネットワーク環境に起因することがあります。\n"
                        . "ご購入の前に FAQ（ISP、Wi-Fi、モバイルデータ、ルーター、端末、ネットワーク設定、接続トラブルの解決）をご確認ください。\n"
                        . "本サービスを違法な目的で利用することはできません。",
                    'warnings' => [
                        'ManduVPN のサーバー障害、システム障害、または中国国内でのブロックにより正常にご利用いただけない場合は、確認された利用不能期間と事情に応じて利用期間を補償・延長いたします。',
                        '関連法令上のクーリングオフ（申込みの撤回）および返金義務がある場合を除き、ManduVPN のサーバーが正常であり、お客様の端末・ISP・Wi-Fi・ルーター・ネットワーク環境などお客様側の原因でご利用が難しい場合には、返金が制限されることがあります。FAQ をご確認のうえ、慎重にご購入ください。',
                        '重大な違法利用が確認された場合、サービスを直ちに制限し、アカウントを解約することがあります。お客様の行為により ManduVPN または第三者に損害が生じた場合、法的な責任を問われることがあります。',
                        '紹介された方の違法行為のみを理由に紹介者のアカウントを自動で停止することはありません。ただし、紹介者が加担した場合、認識した後も支援・ほう助した場合、違法利用者を繰り返し紹介した場合、または違法な目的で紹介制度を悪用した場合は、紹介者のアカウントも停止・解約されることがあります。',
                    ],
                    'checkbox_label' => '上記の内容をすべて読み、確認しました。',
                ],
            ],
        ];
    }
}
