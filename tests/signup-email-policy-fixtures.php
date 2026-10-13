<?php

/**
 * ManduVPN: fixtures for SignupEmailPolicyService (Owner signup decision, 2026-10-13).
 *
 *   php tests/signup-email-policy-fixtures.php
 *
 * ZERO DEPENDENCIES ON PURPOSE. No composer, no vendor/, no .env, no database, no cache, no app container.
 * It requires two source files and stubs the two global helpers they call, so it runs on a bare PHP binary.
 * That is deliberate: this repository has no test suite and no vendor directory, so a fixture that needed
 * either could not be run by anyone.
 *
 * NOT RUN BY THE AUTHOR. There is no PHP binary in the cell these files were written in, so this script has
 * never executed. Whoever has PHP runs it; a failing line names the case.
 *
 * WHAT IS OUT OF REACH HERE, so nobody reads a pass as more than it is:
 *   - code EXPIRY is a cache TTL, not a decision in this service. The fixtures prove an expired code cannot
 *     verify by proving the key is absent-or-different, not by waiting 300 seconds.
 *   - the HTTP layer (validator rules, status codes, response envelope) is not exercised.
 *   - admin_setting() is stubbed, so this says nothing about how settings are persisted.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- stubs for the two global helpers

/** Translation helper: the fixtures assert on machine codes, so returning the key is enough. */
if (!function_exists('__')) {
    function __($key, $replace = [], $locale = null)
    {
        return $key;
    }
}

/** Settings storage, swapped per case by $GLOBALS['fixture_settings']. */
if (!function_exists('admin_setting')) {
    function admin_setting($key = null, $default = null)
    {
        $store = $GLOBALS['fixture_settings'] ?? [];
        return array_key_exists($key, $store) ? $store[$key] : $default;
    }
}

$GLOBALS['fixture_settings'] = [];

require __DIR__ . '/../app/Utils/CacheKey.php';
require __DIR__ . '/../app/Services/SignupEmailPolicyService.php';

use App\Services\SignupEmailPolicyService as P;

// ---------------------------------------------------------------- tiny harness

$pass = 0;
$fail = [];

function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        return;
    }
    $fail[] = sprintf("FAIL  %s\n        expected: %s\n        actual:   %s", $name, var_export($expected, true), var_export($actual, true));
}

/** The machine code of a refusal, or null when accepted. */
function codeOf(?array $result): ?string
{
    return $result === null ? null : $result[0];
}

$KR = P::DEFAULT_DOMAINS['kr'];
$FOREIGN = P::DEFAULT_DOMAINS['foreign'];

// ---------------------------------------------------------------- A. the Owner's table, exactly

check('KR list is the confirmed six', $KR, ['naver.com', 'daum.net', 'hanmail.net', 'gmail.com', 'kakao.com', 'nate.com']);
check('FOREIGN list is those six plus qq.com and 163.com', $FOREIGN, ['naver.com', 'daum.net', 'hanmail.net', 'gmail.com', 'kakao.com', 'nate.com', 'qq.com', '163.com']);
check('qq.com is absent from the KR list', in_array('qq.com', $KR, true), false);
check('163.com is absent from the KR list', in_array('163.com', $KR, true), false);

// ---------------------------------------------------------------- B. region: refused, never defaulted

check('region kr', P::normalizeRegion('kr'), 'kr');
check('region foreign', P::normalizeRegion('foreign'), 'foreign');
check('region is case and space insensitive', P::normalizeRegion('  KR '), 'kr');
check('unknown region is null, not a default', P::normalizeRegion('us'), null);
check('empty region is null', P::normalizeRegion(''), null);
check('missing region is null', P::normalizeRegion(null), null);
check('non-string region is null', P::normalizeRegion(123), null);
check('region list injection is null', P::normalizeRegion('kr,foreign'), null);

// ---------------------------------------------------------------- C. address shape: the '@' bypass

check('plain address splits', P::domainOf('user@naver.com'), 'naver.com');
check('address is lowercased', P::domainOf('User@NAVER.COM'), 'naver.com');
check('two @ is malformed, NOT domain "b"', P::domainOf('user@b@naver.com'), null);
check('@ inside the local part is malformed', P::domainOf('us@er@gmail.com'), null);
check('no @ is malformed', P::domainOf('usernaver.com'), null);
check('trailing @ is malformed', P::domainOf('user@'), null);
check('leading @ is malformed', P::domainOf('@naver.com'), null);
check('blank local part is malformed', P::domainOf('   @naver.com'), null);
check('blank domain is malformed', P::domainOf('user@   '), null);
check('non-string is malformed', P::domainOf(null), null);

// ---------------------------------------------------------------- D. KR route: accept, and reject qq/163

check('KR accepts naver.com', codeOf(P::validateAddress('user@naver.com', $KR)), null);
check('KR accepts daum.net', codeOf(P::validateAddress('user@daum.net', $KR)), null);
check('KR accepts hanmail.net', codeOf(P::validateAddress('user@hanmail.net', $KR)), null);
check('KR accepts gmail.com', codeOf(P::validateAddress('user@gmail.com', $KR)), null);
check('KR accepts kakao.com', codeOf(P::validateAddress('user@kakao.com', $KR)), null);
check('KR accepts nate.com', codeOf(P::validateAddress('user@nate.com', $KR)), null);
check('KR REJECTS qq.com', codeOf(P::validateAddress('user@qq.com', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('KR REJECTS 163.com', codeOf(P::validateAddress('user@163.com', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('KR accepts a mixed-case allowed domain', codeOf(P::validateAddress('user@NaVer.CoM', $KR)), null);

// ---------------------------------------------------------------- E. FOREIGN route: the two extra domains

check('FOREIGN accepts qq.com', codeOf(P::validateAddress('user@qq.com', $FOREIGN)), null);
check('FOREIGN accepts 163.com', codeOf(P::validateAddress('user@163.com', $FOREIGN)), null);
check('FOREIGN accepts naver.com too', codeOf(P::validateAddress('user@naver.com', $FOREIGN)), null);
check('FOREIGN rejects a domain on neither list', codeOf(P::validateAddress('user@outlook.com', $FOREIGN)), P::ERR_DOMAIN_NOT_ALLOWED);

// ---------------------------------------------------------------- F. forged domains

check('fake subdomain is rejected', codeOf(P::validateAddress('user@naver.com.attacker.net', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('fake suffix is rejected', codeOf(P::validateAddress('user@xnaver.com', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('allowed domain as a subdomain label is rejected', codeOf(P::validateAddress('user@mail.naver.com', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('allowed domain with a different TLD is rejected', codeOf(P::validateAddress('user@naver.co', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('allowed domain smuggled past a second @ is MALFORMED', codeOf(P::validateAddress('user@evil.net@naver.com', $KR)), P::ERR_ADDRESS_MALFORMED);
check('partial address is MALFORMED not domain-rejected', codeOf(P::validateAddress('user@', $KR)), P::ERR_ADDRESS_MALFORMED);

// ---------------------------------------------------------------- G. empty or abnormal policy: fail closed

check('an empty list accepts nothing', codeOf(P::validateAddress('user@naver.com', [])), P::ERR_POLICY_UNAVAILABLE);
check('normalize lowercases, trims and de-duplicates', P::normalizeDomains(['NAVER.COM ', ' gmail.com', 'gmail.com']), ['naver.com', 'gmail.com']);
check('normalize accepts a comma string', P::normalizeDomains('naver.com, gmail.com'), ['naver.com', 'gmail.com']);
check('a wildcard entry is dropped', P::normalizeDomains(['*.naver.com']), []);
check('an @-prefixed entry is dropped', P::normalizeDomains(['@naver.com']), []);
check('a bare label with no dot is dropped', P::normalizeDomains(['naver']), []);
check('a URL entry is dropped', P::normalizeDomains(['http://naver.com']), []);
check('a path entry is dropped', P::normalizeDomains(['naver.com/x']), []);
check('a blank entry is dropped', P::normalizeDomains(['', '   ']), []);
check('non-string entries are dropped', P::normalizeDomains([123, null, ['naver.com']]), []);
check('a non-list policy normalizes to empty', P::normalizeDomains(null), []);
check('garbage normalizing to empty then accepts nothing', codeOf(P::validateAddress('user@naver.com', P::normalizeDomains(['*.naver.com']))), P::ERR_POLICY_UNAVAILABLE);

// ---------------------------------------------------------------- H. settings-backed resolution

$GLOBALS['fixture_settings'] = [];
check('no stored row falls back to the KR default', P::domainsFor('kr'), $KR);
check('no stored row falls back to the FOREIGN default', P::domainsFor('foreign'), $FOREIGN);
check('an unknown region resolves to no list', P::domainsFor('us'), []);

$GLOBALS['fixture_settings'] = ['manduvpn_signup_email_domains_kr' => []];
check('an EMPTIED KR list closes the KR route, it does not reset to default', P::domainsFor('kr'), []);
check('the emptied KR route then refuses an otherwise valid address', codeOf(P::validateRequest('kr', 'user@naver.com')), P::ERR_POLICY_UNAVAILABLE);
check('emptying KR leaves FOREIGN alone', P::domainsFor('foreign'), $FOREIGN);

$GLOBALS['fixture_settings'] = ['manduvpn_signup_email_domains_kr' => ['*.naver.com', 'naver']];
check('a KR list of only garbage closes the route', P::domainsFor('kr'), []);

$GLOBALS['fixture_settings'] = ['manduvpn_signup_email_domains_kr' => ['NAVER.COM', 'naver.com', ' kakao.com ']];
check('a stored KR list is normalized on read', P::domainsFor('kr'), ['naver.com', 'kakao.com']);
check('a narrowed KR list rejects a domain it dropped', codeOf(P::validateRequest('kr', 'user@gmail.com')), P::ERR_DOMAIN_NOT_ALLOWED);
check('a narrowed KR list still accepts what it kept', codeOf(P::validateRequest('kr', 'user@kakao.com')), null);

// ---------------------------------------------------------------- I. direct-request bypass

$GLOBALS['fixture_settings'] = [];
check('a request with no region is refused', codeOf(P::validateRequest(null, 'user@naver.com')), P::ERR_REGION_REQUIRED);
check('a request with a blank region is refused', codeOf(P::validateRequest('   ', 'user@naver.com')), P::ERR_REGION_REQUIRED);
check('a request with an invented region is refused', codeOf(P::validateRequest('jp', 'user@naver.com')), P::ERR_REGION_UNKNOWN);
check('a KR request for qq.com is refused end to end', codeOf(P::validateRequest('kr', 'user@qq.com')), P::ERR_DOMAIN_NOT_ALLOWED);
check('a FOREIGN request for qq.com is accepted end to end', codeOf(P::validateRequest('foreign', 'user@qq.com')), null);
check('a forged domain is refused end to end', codeOf(P::validateRequest('kr', 'user@naver.com.attacker.net')), P::ERR_DOMAIN_NOT_ALLOWED);

// ---------------------------------------------------------------- J. the code is scoped: address and region

$krKey = P::codeCacheKey('user@naver.com', 'kr');
$foreignKey = P::codeCacheKey('user@naver.com', 'foreign');
$otherAddr = P::codeCacheKey('other@naver.com', 'kr');

check('the two regions do not share a code key', $krKey === $foreignKey, false);
check('two addresses do not share a code key', $krKey === $otherAddr, false);
check('address case does not change the key', P::codeCacheKey('USER@Naver.com', 'kr'), $krKey);
check('surrounding space does not change the key', P::codeCacheKey(' user@naver.com ', 'kr'), $krKey);
check('the key is scoped by purpose', strpos($krKey, P::PURPOSE . ':kr:') !== false, true);
check('the key is NOT the legacy unscoped key', $krKey === App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'user@naver.com'), false);

// A send whose response arrives after the user edited the address: the code was stored under the OLD
// address's key, so it cannot verify the new one. This is the whole mechanism, asserted as key inequality
// because no cache is involved in the decision.
check('a code stored for the old address cannot key the new one',
    P::codeCacheKey('old@naver.com', 'kr') === P::codeCacheKey('new@naver.com', 'kr'), false);
check('a code stored for the KR flow cannot key a FOREIGN registration',
    P::codeCacheKey('user@gmail.com', 'kr') === P::codeCacheKey('user@gmail.com', 'foreign'), false);

// ---------------------------------------------------------------- J2. the global flags are irrelevant
// THE CASE THAT PROVES THE INVARIANT RATHER THAN THE CONFIGURATION. Live has email_verify = 0 and
// email_whitelist_enable = 0, which is exactly why signup needed no code and accepted any domain. Pin
// both flags OFF explicitly and assert the policy still enforces: if this service ever starts consulting
// them, these four lines fail, and a silently inert gate in production is what they catch.
$GLOBALS['fixture_settings'] = ['email_verify' => 0, 'email_whitelist_enable' => 0];
check('with email_verify=0 the KR policy still rejects qq.com',
    codeOf(P::validateRequest('kr', 'user@qq.com')), P::ERR_DOMAIN_NOT_ALLOWED);
check('with the whitelist flag off a forged domain is still rejected',
    codeOf(P::validateRequest('kr', 'user@naver.com.attacker.net')), P::ERR_DOMAIN_NOT_ALLOWED);
check('with both flags off a missing region is still refused',
    codeOf(P::validateRequest(null, 'user@naver.com')), P::ERR_REGION_REQUIRED);
check('with both flags off the published policy still requires the code',
    P::publicPolicy()['email_code_required'], true);

// ---------------------------------------------------------------- K. published policy

$GLOBALS['fixture_settings'] = [];
$policy = P::publicPolicy();
check('the published policy lists both regions', $policy['regions'], ['kr', 'foreign']);
check('the published policy carries the KR domains', $policy['email_domains']['kr'], $KR);
check('the published policy carries the FOREIGN domains', $policy['email_domains']['foreign'], $FOREIGN);
check('the published policy states the code is required', $policy['email_code_required'], true);
check('the published policy states phone signup is preparing', $policy['phone_status'], 'preparing');
check('the published policy is available with the defaults', $policy['available'], true);

$GLOBALS['fixture_settings'] = ['manduvpn_signup_email_domains_kr' => []];
check('an unusable list makes the published policy UNAVAILABLE', P::publicPolicy()['available'], false);

// ---------------------------------------------------------------- report

if ($fail) {
    foreach ($fail as $f) {
        fwrite(STDERR, $f . "\n");
    }
    fwrite(STDERR, sprintf("\n%d failed, %d passed.\n", count($fail), $pass));
    exit(1);
}

printf("PASS  %d checks, 0 failures\n", $pass);
printf("      Owner table: KR = %s\n", implode(', ', $KR));
printf("                   FOREIGN = %s\n", implode(', ', $FOREIGN));
printf("      not covered here: code TTL expiry, the HTTP layer, settings persistence\n");
exit(0);
