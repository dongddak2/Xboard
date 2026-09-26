<?php
/**
 * ManduVPN RC (QA-B1): runner for the seven FRAGMENT regressions.
 *
 * test_dual_currency / test_toss_security / test_v2_flow / test1_period / test2_pricing /
 * test3_planresource / test4_planresource_notice are not standalone scripts: they have no `<?php`
 * opener, no `require vendor/autoload.php` and no bootstrap, and they use App\... classes from the
 * first line. Running them with `php <file>` therefore prints the source as plain text (no `<?php`)
 * instead of executing it - which is a CALLING error, not a regression failure.
 *
 * They are `php artisan tinker --execute=` snippets. Passing a multi-line snippet through a shell
 * argument is fragile, so this runner bootstraps the framework the same way the other 18 tests do and
 * then evaluates the fragment. The seven originals stay byte-identical (RC_CONTRACTS.md §1.2 cond. 1);
 * this file adds no write path of its own - each fragment keeps its own beginTransaction/rollBack.
 *
 * Usage (test server only, never production):
 *   docker exec -w /www xboard-test-web-1 php /www/.manduvpn-backup/run_snippet.php test_dual_currency.php
 * The argument is resolved inside .manduvpn-backup/ and must be one of the seven fragments.
 *
 * One fragment per process: two of them declare functions at global scope, so a second eval in the
 * same process would be a redeclaration.
 */

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const MANDUVPN_FRAGMENTS = [
    'test_dual_currency.php',
    'test_toss_security.php',
    'test_v2_flow.php',
    'test1_period.php',
    'test2_pricing.php',
    'test3_planresource.php',
    'test4_planresource_notice.php',
];

$name = basename((string) ($argv[1] ?? ''));
if (!in_array($name, MANDUVPN_FRAGMENTS, true)) {
    fwrite(STDERR, "usage: php run_snippet.php <fragment>\nknown fragments:\n  " . implode("\n  ", MANDUVPN_FRAGMENTS) . "\n");
    exit(2);
}
$path = __DIR__ . '/' . $name;
if (!is_file($path)) {
    fwrite(STDERR, "fragment not found: $path\n");
    exit(2);
}
$code = file_get_contents($path);
if ($code === false) {
    fwrite(STDERR, "fragment could not be read: $path\n");
    exit(2);
}
if (str_starts_with(ltrim($code), '<?php')) {
    // A fragment that grew a `<?php` opener is a standalone test now - run it directly instead.
    fwrite(STDERR, "$name now opens with <?php; run it directly with `php $name`\n");
    exit(2);
}
echo "===== $name (via run_snippet.php) =====" . PHP_EOL;
eval($code);
