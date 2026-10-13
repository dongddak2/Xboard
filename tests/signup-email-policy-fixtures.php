<?php

/**
 * ManduVPN: fixtures for SignupEmailPolicyService (Owner signup decision, 2026-10-13).
 *
 *   php tests/signup-email-policy-fixtures.php
 *
 * ZERO DEPENDENCIES ON PURPOSE. No composer, no vendor/, no .env, no database, no cache, no app container.
 * Two source requires and one stubbed global, so it runs on a bare PHP binary. This repository has no test
 * suite and no vendor directory, so a fixture that needed either could not be run by anyone.
 *
 * NOT RUN BY THE AUTHOR. There is no PHP binary in the cell these files were written in, so this script has
 * never executed. Whoever has PHP runs it; a failing line names the case.
 *
 * THERE IS NO LONGER AN admin_setting() STUB, AND THAT IS THE POINT.
 * The first version of this file stubbed it as `array_key_exists($k, $store) ? $store[$k] : $default`,
 * which returns a stored null AS null. The real helper is `->get($key) ?? $default`
 * (Helpers/Functions.php:23), which turns a stored null INTO the default. The two agreed on every value
 * except null - and null is the one value the validator admits - so the fixture passed green while an
 * emptied admin list silently reopened with the Owner's defaults. QA caught it; the stub did not.
 * So the decisions now live in PURE functions that take their data as arguments, the fixture drives those
 * directly, and section L below reproduces the real `??` resolution explicitly to show what it does.
 * A stub must be checked against the real resolver at the boundary values the validator admits; the
 * cheapest way to satisfy that is to have no stub in the decision path at all.
 *
 * WHAT IS OUT OF REACH HERE:
 *   - code TTL expiry is cache behaviour, not a decision this service makes. Absence and mismatch are
 *     proven instead.
 *   - the HTTP layer (validator rules, status codes, response envelope) is not exercised.
 *   - the container-backed shell - domainsFor(), validateRequest(), publicPolicy() - needs Laravel. Their
 *     pure cores (resolveDomains, validateRegionOnly, validateAddress, publicPolicyFrom) are what is tested.
 */

declare(strict_types=1);

/** Translation helper: the fixtures assert on machine codes, so returning the key is enough. */
if (!function_exists('__')) {
    function __($key, $replace = [], $locale = null)
    {
        return $key;
    }
}

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

// ---------------------------------------------------------------- H. direct-request bypass: the region half

check('a request with no region is refused', codeOf(P::validateRegionOnly(null)), P::ERR_REGION_REQUIRED);
check('a request with a blank region is refused', codeOf(P::validateRegionOnly('   ')), P::ERR_REGION_REQUIRED);
check('a request with an invented region is refused', codeOf(P::validateRegionOnly('jp')), P::ERR_REGION_UNKNOWN);
check('a usable region passes the region half', P::validateRegionOnly('kr'), null);
check('a usable foreign region passes too', P::validateRegionOnly('foreign'), null);

// end to end, composed exactly as validateRequest() composes it
check('a KR request for qq.com is refused end to end',
    codeOf(P::validateAddress('user@qq.com', P::resolveDomains(P::ABSENT, 'kr'))), P::ERR_DOMAIN_NOT_ALLOWED);
check('a FOREIGN request for qq.com is accepted end to end',
    codeOf(P::validateAddress('user@qq.com', P::resolveDomains(P::ABSENT, 'foreign'))), null);
check('a forged domain is refused end to end',
    codeOf(P::validateAddress('user@naver.com.attacker.net', P::resolveDomains(P::ABSENT, 'kr'))), P::ERR_DOMAIN_NOT_ALLOWED);

// ---------------------------------------------------------------- I. the code is scoped: address and region

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
// address's key, so it cannot verify the new one.
check('a code stored for the old address cannot key the new one',
    P::codeCacheKey('old@naver.com', 'kr') === P::codeCacheKey('new@naver.com', 'kr'), false);
check('a code stored for the KR flow cannot key a FOREIGN registration',
    P::codeCacheKey('user@gmail.com', 'kr') === P::codeCacheKey('user@gmail.com', 'foreign'), false);

// ---------------------------------------------------------------- J. the global flags are irrelevant
// THE CASE THAT PROVES THE INVARIANT RATHER THAN THE CONFIGURATION. Live has email_verify = 0 and
// email_whitelist_enable = 0, which is exactly why signup needed no code and accepted any domain. The
// policy must not consult either, so there is nothing to set here - and that is the assertion: the service
// resolves a verdict from its ARGUMENTS alone, with no settings read in the decision path at all.
check('the KR verdict comes from the domain list, not from any flag',
    codeOf(P::validateAddress('user@qq.com', $KR)), P::ERR_DOMAIN_NOT_ALLOWED);
check('the published policy requires the code unconditionally',
    P::publicPolicyFrom(['kr' => $KR, 'foreign' => $FOREIGN])['email_code_required'], true);

// ---------------------------------------------------------------- K. published policy

$policy = P::publicPolicyFrom(['kr' => $KR, 'foreign' => $FOREIGN]);
check('the published policy lists both regions', $policy['regions'], ['kr', 'foreign']);
check('the published policy carries the KR domains', $policy['email_domains']['kr'], $KR);
check('the published policy carries the FOREIGN domains', $policy['email_domains']['foreign'], $FOREIGN);
check('the published policy states phone signup is preparing', $policy['phone_status'], 'preparing');
check('the published policy is available with both lists populated', $policy['available'], true);
check('an unusable list makes the published policy UNAVAILABLE',
    P::publicPolicyFrom(['kr' => [], 'foreign' => $FOREIGN])['available'], false);
check('an unusable FOREIGN list does the same',
    P::publicPolicyFrom(['kr' => $KR, 'foreign' => []])['available'], false);

// ---------------------------------------------------------------- L. THE NULL DEFECT, as a regression test
// An emptied admin list used to REOPEN the route with the Owner's defaults, because it reaches the resolver
// as null and the real admin_setting() is `->get($key) ?? $default`. These are the boundary values the
// validator admits, which is where a stub must be checked and where this one was not.

check('an ABSENT key falls back to the Owner defaults, so a fresh install works', P::resolveDomains(P::ABSENT, 'kr'), $KR);
check('an ABSENT foreign key falls back too', P::resolveDomains(P::ABSENT, 'foreign'), $FOREIGN);
check('a stored NULL CLOSES the route - this is the defect', P::resolveDomains(null, 'kr'), []);
check('a stored empty array closes the route', P::resolveDomains([], 'kr'), []);
check('a stored empty string closes the route', P::resolveDomains('', 'kr'), []);
check('a stored list of garbage closes the route', P::resolveDomains(['*.naver.com'], 'kr'), []);
check('a stored scalar closes the route', P::resolveDomains(0, 'kr'), []);
check('an unknown region resolves to no list', P::resolveDomains(P::ABSENT, 'us'), []);
check('a narrowed stored list is honoured rather than widened',
    P::resolveDomains(['naver.com', 'KAKAO.COM '], 'kr'), ['naver.com', 'kakao.com']);
check('the closed KR route then refuses an otherwise valid address',
    codeOf(P::validateAddress('user@naver.com', P::resolveDomains(null, 'kr'))), P::ERR_POLICY_UNAVAILABLE);
check('closing KR does not touch FOREIGN', P::resolveDomains(P::ABSENT, 'foreign'), $FOREIGN);

// The mechanism itself, reproduced rather than described: this is what admin_setting() does, and it is why
// these two keys are not read through it. If anyone reintroduces that call, this pair explains the damage.
$adminSettingResolution = static fn ($stored, $default) => $stored ?? $default;
check('the admin_setting resolution turns a stored NULL into the defaults',
    $adminSettingResolution(null, P::DEFAULT_DOMAINS['kr']), $KR);
check('so reading these keys through it would REOPEN an emptied list',
    P::normalizeDomains($adminSettingResolution(null, P::DEFAULT_DOMAINS['kr'])) === [], false);
check('whereas the resolver this service uses keeps it closed',
    P::resolveDomains(null, 'kr') === [], true);

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
printf("      an ABSENT key defaults; a stored null/[]/garbage CLOSES the route\n");
printf("      no admin_setting stub in the decision path, by design\n");
printf("      not covered here: code TTL expiry, the HTTP layer, the container-backed shell\n");
exit(0);
