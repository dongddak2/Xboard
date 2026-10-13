<?php

namespace App\Services;

use App\Utils\CacheKey;

/**
 * ManduVPN: the signup email policy (Owner decision, 2026-10-13).
 *
 * ---------------------------------------------------------------------------------------------------
 * DEPLOY ORDER, AND IT IS NOT OPTIONAL: THE CLIENTS SHIP FIRST.
 *
 * DEPLOYING THIS BEFORE THE CLIENTS SEND `region` BREAKS ALL SIGNUPS. Both signup endpoints now require
 * a `region` parameter and refuse a request without one (SIGNUP_REGION_REQUIRED, 422). There is no
 * default region on purpose - defaulting would be the silent fallback the Owner banned, and a loud 422
 * is better than a quiet signup under the wrong policy. So the web screens and the app client that send
 * `region` must be released BEFORE this backend; in the gap, every signup 422s.
 *
 * SECOND, FOR WHOEVER WRITES THE SCREEN: the public config still reports the old global flag as
 * `is_email_verify`, and it is still 0. Registration now ALWAYS requires a code. A screen that reads
 * `is_email_verify` will therefore hide the code field and the user can never complete signup. Read
 * `signup_email_policy.email_code_required` instead. `is_email_verify` is left alone because other
 * flows read it.
 * ---------------------------------------------------------------------------------------------------
 *
 * WHAT THIS REPLACES. Two global switches used to decide this, and both are 0 in live:
 *   - AuthController::register :93  ran the domain whitelist only `if (admin_setting('email_whitelist_enable'))`
 *   - AuthController::register :126 required the email code only `if (admin_setting('email_verify'))`
 * So today an email signup needs no verification code and accepts any domain, and
 * Passport\CommController::sendEmailVerify has no domain gate at all. This service is the gate instead, and it
 * does NOT consult either global flag: for the two email signup routes a verified code is always required.
 *
 * THE BACKEND IS THE GATE, NOT THE SCREEN. The same three call sites share this one service:
 *   1. V1\Guest\CommController::config        publishes the policy, so a screen reads it instead of hardcoding
 *   2. V1\Passport\CommController::sendEmailVerify  validates region + address BEFORE sending anything
 *   3. V1\Passport\AuthController::register    validates region + address, then requires the code
 * A client that posts straight to the API with a forged domain or no region is refused exactly as the form
 * would be refused.
 *
 * EXACT DOMAIN EQUALITY, NOT A SUFFIX TEST. Helper::emailSuffixVerify() splits on '@' and takes element [1],
 * so `a@b@naver.com` reads as domain `b`, and it never lowercases. This service requires exactly one '@' and
 * compares the lowercased domain for EQUALITY against the allow-list, which is what rejects
 * `naver.com.attacker.net` (fake subdomain), `xnaver.com` (fake suffix) and `a@b@naver.com` (second '@' used
 * to smuggle a domain into the local part). The local part is therefore allowed to contain no '@' at all.
 *
 * FAIL CLOSED, NEVER FALL BACK. An unknown region, a missing region, or an empty/garbage configured list are
 * all refusals. There is deliberately no default region: guessing one would turn a broken client into an
 * accepted signup under the wrong policy.
 *
 * THE CODE IS SCOPED TO (purpose, region, address). codeCacheKey() binds all three, so:
 *   - a code sent for the KR flow cannot verify a FOREIGN registration,
 *   - a code sent to one address cannot verify another,
 *   - and a send whose response arrives late cannot license the address the user has since typed, because the
 *     stored code never belonged to that address in the first place.
 * That is what "changing the address or the region clears the verification state" means on the server; the
 * screen clearing its own state is a convenience, not the guarantee.
 *
 * REGION IS NOT NATIONALITY. It selects a domain policy and nothing else. Creating an identity check is
 * explicitly out of scope, and no code here may be read as proof of a user's nationality.
 *
 * Defaults are the Owner's table; both lists are admin-editable through ConfigSave/ConfigController and live
 * in the existing settings storage (two v2_settings rows, no schema change).
 */
class SignupEmailPolicyService
{
    /** Signup regions. A region outside this list is refused, never defaulted. */
    public const REGIONS = ['kr', 'foreign'];

    /** Settings keys - existing settings storage, one row each, no migration. */
    public const KEY_DOMAINS = [
        'kr' => 'manduvpn_signup_email_domains_kr',
        'foreign' => 'manduvpn_signup_email_domains_foreign',
    ];

    /** Owner's confirmed table (2026-10-13). KR is the six; FOREIGN is those six plus two. */
    public const DEFAULT_DOMAINS = [
        'kr' => ['naver.com', 'daum.net', 'hanmail.net', 'gmail.com', 'kakao.com', 'nate.com'],
        'foreign' => ['naver.com', 'daum.net', 'hanmail.net', 'gmail.com', 'kakao.com', 'nate.com', 'qq.com', '163.com'],
    ];

    /** Verification-code scope. Part of the cache key, so codes cannot cross purposes. */
    public const PURPOSE = 'signup';

    /** Phone signup is PREPARING: published so a screen shows it as such instead of offering a dead button. */
    public const PHONE_STATUS = 'preparing';

    /** Machine codes. The message is localizable; this is what a client branches on. */
    public const ERR_REGION_REQUIRED = 'SIGNUP_REGION_REQUIRED';
    public const ERR_REGION_UNKNOWN = 'SIGNUP_REGION_UNKNOWN';
    public const ERR_POLICY_UNAVAILABLE = 'SIGNUP_POLICY_UNAVAILABLE';
    public const ERR_ADDRESS_MALFORMED = 'SIGNUP_EMAIL_MALFORMED';
    public const ERR_DOMAIN_NOT_ALLOWED = 'SIGNUP_EMAIL_DOMAIN_NOT_ALLOWED';

    // ---------------------------------------------------------------- pure decisions
    // Everything above the "settings" section below takes its data as an argument and touches no framework,
    // so the fixtures can exercise it without a database, a cache or an app container.

    /**
     * The region, or null when it is absent or not one of REGIONS.
     * Null is a refusal for the caller to report - it is never replaced with a default.
     */
    public static function normalizeRegion($region): ?string
    {
        if (!is_string($region)) {
            return null;
        }
        $region = strtolower(trim($region));
        return in_array($region, self::REGIONS, true) ? $region : null;
    }

    /**
     * Normalize a configured list: lowercase, trim, drop blanks and anything that is not a bare domain,
     * de-duplicate. A list that normalizes to empty is an unusable policy and the caller must fail closed.
     */
    public static function normalizeDomains($domains): array
    {
        if (is_string($domains)) {
            $domains = preg_split('/[,\s]+/', $domains);
        }
        if (!is_array($domains)) {
            return [];
        }
        $out = [];
        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                continue;
            }
            $domain = strtolower(trim($domain));
            // A bare domain only: no '@', no scheme, no path, no wildcard. This is what keeps a configured
            // "*.naver.com" or "@naver.com" from quietly widening the policy.
            if ($domain === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
                continue;
            }
            $out[$domain] = true;
        }
        return array_keys($out);
    }

    /**
     * The domain of an address, lowercased - or null when the address cannot be split exactly once.
     * Exactly one '@' is required: `a@b@naver.com` and `a@` and `@naver.com` all return null.
     */
    public static function domainOf($email): ?string
    {
        if (!is_string($email) || substr_count($email, '@') !== 1) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        if (trim($local) === '' || trim($domain) === '') {
            return null;
        }
        return strtolower(trim($domain));
    }

    /**
     * Validate an address against an already-resolved allow-list.
     * Returns null when acceptable, or [machine_code, message] to be reported as a refusal.
     *
     * $domains is passed in rather than read here so this stays a pure function: the fixtures call it
     * directly with a forged, empty or malformed list.
     */
    public static function validateAddress($email, array $domains): ?array
    {
        if ($domains === []) {
            // An empty policy accepts nothing. The alternative - accepting everything - is the bug.
            return [self::ERR_POLICY_UNAVAILABLE, __('Signup email policy is unavailable, please try again later')];
        }
        $domain = self::domainOf($email);
        if ($domain === null) {
            return [self::ERR_ADDRESS_MALFORMED, __('Email format is incorrect')];
        }
        if (!in_array($domain, $domains, true)) {
            return [self::ERR_DOMAIN_NOT_ALLOWED, __('This email domain cannot be used for this signup')];
        }
        return null;
    }

    /**
     * Cache key for the signup verification code, scoped to purpose + region + address.
     * The address is lowercased so the code survives a case change in what the user typed but still cannot
     * move to a different mailbox.
     */
    public static function codeCacheKey(string $email, string $region): string
    {
        return CacheKey::get('EMAIL_VERIFY_CODE', self::PURPOSE . ':' . $region . ':' . strtolower(trim($email)));
    }

    // ---------------------------------------------------------------- settings-backed

    /** The configured allow-list for a region, falling back to the Owner's default when nothing is stored. */
    public static function domainsFor(string $region): array
    {
        if (!isset(self::KEY_DOMAINS[$region])) {
            return [];
        }
        $stored = admin_setting(self::KEY_DOMAINS[$region], self::DEFAULT_DOMAINS[$region]);
        $domains = self::normalizeDomains($stored);
        // A stored row that normalizes to nothing is a broken policy, not a reason to use the default: an admin
        // who emptied the list meant to close the route, and falling back would re-open it behind their back.
        return $domains;
    }

    /**
     * Resolve region + address in one step for a request handler.
     * Returns null when acceptable, or [machine_code, message].
     */
    public static function validateRequest($region, $email): ?array
    {
        if ($region === null || (is_string($region) && trim($region) === '')) {
            return [self::ERR_REGION_REQUIRED, __('Signup region is required')];
        }
        $normalized = self::normalizeRegion($region);
        if ($normalized === null) {
            return [self::ERR_REGION_UNKNOWN, __('Signup region is not supported')];
        }
        return self::validateAddress($email, self::domainsFor($normalized));
    }

    /**
     * What the public config publishes, so a screen reads the policy instead of carrying its own copy.
     * `available` is false when either list is unusable - a screen must then refuse to offer signup rather
     * than show an empty dropdown that looks like it might work.
     */
    public static function publicPolicy(): array
    {
        $domains = [];
        foreach (self::REGIONS as $region) {
            $domains[$region] = self::domainsFor($region);
        }
        return [
            'regions' => self::REGIONS,
            'email_domains' => $domains,
            'email_code_required' => true,
            'phone_status' => self::PHONE_STATUS,
            'available' => !in_array([], $domains, true),
        ];
    }
}
