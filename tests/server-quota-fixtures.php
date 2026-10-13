<?php

/**
 * ManduVPN: fixtures for ServerQuotaService (Owner quota decision 2026-10-13, purchase-anchor correction).
 *
 *   php tests/server-quota-fixtures.php
 *
 * ZERO DEPENDENCIES ON PURPOSE. No composer, no vendor/, no .env, no database, no cache, no app container.
 * One source require and one stubbed global, so it runs on a bare PHP binary. This repository has no test
 * suite and no vendor directory, so a fixture that needed either could not be run by anyone.
 *
 * NOT RUN BY THE AUTHOR. There is no PHP binary in the cell these files were written in, so this script has
 * never executed. Whoever has PHP runs it; a failing line names the case.
 *
 * WHAT IS OUT OF REACH HERE, so nobody reads a pass as more than it is:
 *   - the accumulating WRITE path is not built yet: it is blocked on the idempotency ruling, because no node
 *     report payload carries a stable batch identifier. Nothing here exercises double-counting.
 *   - the usage TABLE is not queried: the migration is written and not applied. usedBytes(), stateFor() and
 *     overLimitUserIds() all touch the database or the model layer and are therefore not covered.
 *   - enforcement in ServerService::getAvailableUsers is not exercised; that needs the framework.
 */

declare(strict_types=1);

/** Settings storage, swapped per case by $GLOBALS['fixture_settings']. */
if (!function_exists('admin_setting')) {
    function admin_setting($key = null, $default = null)
    {
        $store = $GLOBALS['fixture_settings'] ?? [];
        return array_key_exists($key, $store) ? $store[$key] : $default;
    }
}

$GLOBALS['fixture_settings'] = [];

require __DIR__ . '/../app/Services/ServerQuotaService.php';

use App\Services\ServerQuotaService as Q;

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

const DAY = 86400;
const P = 30 * DAY;                 // one quota period, in seconds
const GB = 1073741824;
// A deliberately mid-month anchor, so a calendar-month reset and a 30-day reset cannot coincide.
// 2026-01-20 09:00:00 UTC.
const ANCHOR = 1768899600;

// ---------------------------------------------------------------- A. the Owner's rule, as constants

check('a period is 30 days', Q::PERIOD_DAYS, 30);
check('a period is 30 days in seconds', Q::PERIOD_SECONDS, P);
check('a GB is 1073741824 bytes', Q::BYTES_PER_GB, GB);

// ---------------------------------------------------------------- B. period boundaries, both sides

check('at the anchor the period index is 0', Q::periodIndex(ANCHOR, ANCHOR), 0);
check('one second before the first boundary is still period 0', Q::periodIndex(ANCHOR, ANCHOR + P - 1), 0);
check('exactly at the first boundary the period is 1', Q::periodIndex(ANCHOR, ANCHOR + P), 1);
check('one second after the first boundary is period 1', Q::periodIndex(ANCHOR, ANCHOR + P + 1), 1);
check('the twelfth period is index 11', Q::periodIndex(ANCHOR, ANCHOR + (11 * P)), 11);
check('a clock skewed before the anchor is period 0, never negative', Q::periodIndex(ANCHOR, ANCHOR - 99999), 0);

check('the first period starts at the anchor', Q::periodStart(ANCHOR, ANCHOR), ANCHOR);
check('one second before the boundary still starts at the anchor', Q::periodStart(ANCHOR, ANCHOR + P - 1), ANCHOR);
check('at the boundary the period start moves by exactly 30 days', Q::periodStart(ANCHOR, ANCHOR + P), ANCHOR + P);
check('the reset is the end of the current period', Q::nextResetAt(ANCHOR, ANCHOR), ANCHOR + P);
check('one second before the reset, the reset is unchanged', Q::nextResetAt(ANCHOR, ANCHOR + P - 1), ANCHOR + P);
check('at the reset instant the next reset is one period later', Q::nextResetAt(ANCHOR, ANCHOR + P), ANCHOR + (2 * P));

// ---------------------------------------------------------------- C. calendar months are DEAD
// The superseded rule was a first-of-month reset on an Asia/Seoul basis. These assert the replacement, so
// if anyone reintroduces the calendar basis in the code the fixture fails rather than the document.

check('the period is exactly 2592000s, not a calendar month',
    Q::nextResetAt(ANCHOR, ANCHOR) - Q::periodStart(ANCHOR, ANCHOR), 2592000);
check('the reset does not land on the first of a month', gmdate('d', Q::nextResetAt(ANCHOR, ANCHOR)) === '01', false);
check('the reset does not land at midnight either', gmdate('H:i:s', Q::nextResetAt(ANCHOR, ANCHOR)) === '00:00:00', false);
check('the reset keeps the anchor time of day', gmdate('H:i:s', Q::nextResetAt(ANCHOR, ANCHOR)), gmdate('H:i:s', ANCHOR));
// February is the case a calendar implementation gets wrong: the 30-day period never shortens.
check('a period spanning February is still 30 days',
    Q::nextResetAt(ANCHOR, ANCHOR + P) - Q::periodStart(ANCHOR, ANCHOR + P), 2592000);

// ---------------------------------------------------------------- D. an additive renewal cannot reset usage
// This is the free-reset hiding place. Periods are counted from the ORIGINAL anchor, so a renewal part-way
// through a period does not start a fresh one. The function takes no renewal argument at all, which is the
// structural half of the guarantee; these assert the arithmetic half on both sides of the boundary.

$renewalMoment = ANCHOR + (45 * DAY);              // renewed 15 days into the second period
check('a mid-period renewal does not move the period start',
    Q::periodStart(ANCHOR, $renewalMoment), ANCHOR + P);
check('a mid-period renewal does not move the reset',
    Q::nextResetAt(ANCHOR, $renewalMoment), ANCHOR + (2 * P));
check('the reset is NOT 30 days from the renewal',
    Q::nextResetAt(ANCHOR, $renewalMoment) === $renewalMoment + P, false);
check('a second renewal still changes nothing',
    Q::periodStart(ANCHOR, ANCHOR + (75 * DAY)), ANCHOR + (2 * P));
// Switching or reconnecting to a node cannot restart a period either: nothing in the signature can express it.
check('period arithmetic depends only on anchor and now',
    Q::periodStart(ANCHOR, ANCHOR + P + 10) === Q::periodStart(ANCHOR, ANCHOR + P + 20), true);

// ---------------------------------------------------------------- E. unset means unlimited, never a default

check('an unset cap is unlimited', Q::capBytesFrom(null), null);
check('a zero cap is unlimited, not a zero allowance', Q::capBytesFrom(0), null);
check('a negative cap is unlimited rather than nonsense', Q::capBytesFrom(-5), null);
check('a non-numeric cap is unlimited', Q::capBytesFrom('fifty'), null);
check('a float cap is rejected rather than rounded', Q::capBytesFrom(1.5), null);
check('50 GB converts to bytes', Q::capBytesFrom(50), 50 * GB);
check('a digit string converts too', Q::capBytesFrom('50'), 50 * GB);

$caps = ['7' => 50, '9' => 100];
check('a configured server resolves its own cap', Q::capBytesFor(7, $caps), 50 * GB);
check('a different server resolves a different cap', Q::capBytesFor(9, $caps), 100 * GB);
check('an unconfigured server is unlimited', Q::capBytesFor(8, $caps), null);
check('a non-numeric server id is unlimited', Q::capBytesFor('abc', $caps), null);

// ---------------------------------------------------------------- F. the settings row, including garbage

$GLOBALS['fixture_settings'] = [];
check('no configured row means no caps at all', Q::caps(), []);

$GLOBALS['fixture_settings'] = [Q::KEY_CAPS => [7 => 50, '9' => '100']];
check('integer and string keys both normalize', Q::caps(), ['7' => 50, '9' => 100]);
check('and resolve through capBytes', Q::capBytes(7), 50 * GB);
check('an unlisted server stays unlimited', Q::capBytes(123), null);

$GLOBALS['fixture_settings'] = [Q::KEY_CAPS => ['7' => 0, '8' => 'x', 'abc' => 10, '9' => -1, '10' => null]];
check('zero, garbage, bad keys and negatives are all dropped', Q::caps(), []);
check('so every one of those servers is unlimited', Q::capBytes(7), null);

$GLOBALS['fixture_settings'] = [Q::KEY_CAPS => '{"7":50}'];
check('a JSON string row is accepted', Q::caps(), ['7' => 50]);

$GLOBALS['fixture_settings'] = [Q::KEY_CAPS => 'not json'];
check('an unparseable row yields no caps rather than an error', Q::caps(), []);

$GLOBALS['fixture_settings'] = [];

// ---------------------------------------------------------------- G. state(): the figure shown IS the figure enforced

$now = ANCHOR + (10 * DAY);

$unlimited = Q::state(ANCHOR, 3 * GB, null, $now);
check('an unlimited node has no cap', $unlimited['cap_bytes'], null);
check('an unlimited node still reports what was used', $unlimited['used_bytes'], 3 * GB);
check('an unlimited node has no meaningful remaining', $unlimited['remaining_bytes'], null);
check('an unlimited node is never over', $unlimited['over_limit'], false);
check('an unlimited node says so', $unlimited['status'], Q::STATUS_UNLIMITED);

$ok = Q::state(ANCHOR, 20 * GB, 50 * GB, $now);
check('under the cap is ok', $ok['status'], Q::STATUS_OK);
check('remaining is cap minus used', $ok['remaining_bytes'], 30 * GB);
check('under the cap is not over', $ok['over_limit'], false);
check('the reset is the real next boundary', $ok['reset_at'], ANCHOR + P);
check('the period start is the current boundary', $ok['period_start_at'], ANCHOR);

$atCap = Q::state(ANCHOR, 50 * GB, 50 * GB, $now);
check('exactly at the cap is OVER', $atCap['over_limit'], true);
check('exactly at the cap reports over', $atCap['status'], Q::STATUS_OVER);
check('remaining never goes negative', $atCap['remaining_bytes'], 0);

$past = Q::state(ANCHOR, 60 * GB, 50 * GB, $now);
check('past the cap is over', $past['over_limit'], true);
check('remaining is clamped to zero, not negative', $past['remaining_bytes'], 0);

// ---------------------------------------------------------------- H. unknown is NOT zero, and fails OPEN
// The deliberate exception to the fail-closed rule. If these flip to over_limit true, someone who has paid
// is being denied service because of a number we failed to measure.

$unmeasured = Q::state(ANCHOR, null, 50 * GB, $now);
check('an unmeasured figure reports unknown', $unmeasured['status'], Q::STATUS_UNKNOWN);
check('an unmeasured figure is NOT rendered as zero', $unmeasured['used_bytes'], null);
check('an unmeasured figure does not claim a remaining amount', $unmeasured['remaining_bytes'], null);
check('an unmeasured figure FAILS OPEN', $unmeasured['over_limit'], false);
check('an unmeasured figure still shows the cap', $unmeasured['cap_bytes'], 50 * GB);
check('an unmeasured figure still shows the reset', $unmeasured['reset_at'], ANCHOR + P);

$noAnchor = Q::state(null, 60 * GB, 50 * GB, $now);
check('an indeterminate anchor reports unknown', $noAnchor['status'], Q::STATUS_UNKNOWN);
check('an indeterminate anchor FAILS OPEN even when usage looks over', $noAnchor['over_limit'], false);
check('an indeterminate anchor has no period start', $noAnchor['period_start_at'], null);
check('an indeterminate anchor has no reset date to show', $noAnchor['reset_at'], null);
check('an indeterminate anchor does not render usage as zero', $noAnchor['used_bytes'], null);

$bothUnknown = Q::state(null, null, null, $now);
check('no cap and no anchor is unlimited and open', $bothUnknown['over_limit'], false);

// ---------------------------------------------------------------- I. per-user and per-node independence

// Same usage, two different caps: the verdict belongs to the pair, not to the user.
check('the same usage is over on a small cap', Q::state(ANCHOR, 60 * GB, 50 * GB, $now)['over_limit'], true);
check('and under on a larger cap', Q::state(ANCHOR, 60 * GB, 100 * GB, $now)['over_limit'], false);
check('and unlimited where no cap is set', Q::state(ANCHOR, 60 * GB, null, $now)['over_limit'], false);

// Two users with different anchors are in different periods at the same instant.
$anchorB = ANCHOR + (5 * DAY);
check('two anchors give two different period starts',
    Q::periodStart(ANCHOR, ANCHOR + P + DAY) === Q::periodStart($anchorB, ANCHOR + P + DAY), false);
check('and two different resets',
    Q::nextResetAt(ANCHOR, $now) === Q::nextResetAt($anchorB, $now), false);

// ---------------------------------------------------------------- J. lifetime accounts
// expired_at === NULL means no expiry; it does NOT mean no quota. Nothing in the period arithmetic reads an
// expiry, so a lifetime user with an activation anchor behaves exactly like anyone else - asserted so that
// a future "lifetime means unlimited" shortcut breaks here.

check('a lifetime user gets ordinary periods', Q::periodStart(ANCHOR, ANCHOR + (100 * DAY)), ANCHOR + (3 * P));
check('a lifetime user gets an ordinary reset', Q::nextResetAt(ANCHOR, ANCHOR + (100 * DAY)), ANCHOR + (4 * P));
check('a lifetime user is capped normally', Q::state(ANCHOR, 60 * GB, 50 * GB, ANCHOR + (100 * DAY))['over_limit'], true);

// ---------------------------------------------------------------- report

if ($fail) {
    foreach ($fail as $f) {
        fwrite(STDERR, $f . "\n");
    }
    fwrite(STDERR, sprintf("\n%d failed, %d passed.\n", count($fail), $pass));
    exit(1);
}

printf("PASS  %d checks, 0 failures\n", $pass);
printf("      period = %d days (%ds), anchor-counted, calendar months asserted dead\n", Q::PERIOD_DAYS, Q::PERIOD_SECONDS);
printf("      unset cap = unlimited; unmeasured = unknown and FAILS OPEN, never zero\n");
printf("      not covered here: the accumulating write (blocked on idempotency), the usage table\n");
printf("      (migration written not applied), and enforcement in ServerService\n");
exit(0);
