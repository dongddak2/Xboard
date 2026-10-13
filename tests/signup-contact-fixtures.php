<?php
declare(strict_types=1);

namespace Illuminate\Support\Facades {
    class DB {
        public static \PDO $pdo;
        public static bool $fail = false;
        public static function table(string $table): object {
            if ($table !== 'v2_user_contact') throw new \RuntimeException('Unexpected table');
            return new class {
                public function insert(array $values): bool {
                    if (DB::$fail) return false;
                    $fields = array_keys($values);
                    $query = DB::$pdo->prepare('INSERT INTO v2_user_contact (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
                    return $query->execute(array_values($values));
                }
            };
        }
    }
}

namespace {
    require __DIR__ . '/../app/Services/SignupContactService.php';
    use App\Services\SignupContactService as Contact;
    use Illuminate\Support\Facades\DB;
    $checks = 0;
    function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new \RuntimeException($label); $checks++; }
    $base = ['real_name' => '홍 길동', 'wechat_id' => 'contact_demo', 'phone_country_code' => '+82', 'phone_number' => '010-1234-5678', 'consent_terms' => true, 'consent_privacy' => true, 'contact_version' => Contact::VERSION];
    check(Contact::validationError($base) === null, 'KR accepted');
    check(Contact::normalize($base)['marketing_consent'] === 0, 'Marketing defaults to refusal');
    check(Contact::normalize(array_replace($base, ['consent_marketing' => true]))['marketing_consent'] === 1, 'Explicit marketing choice retained');
    check(Contact::normalize($base)['phone_number'] === '1012345678', 'KR trunk zero normalized');
    check(Contact::validationError(array_replace($base, ['real_name' => "Anne O'Neil", 'phone_country_code' => '+1', 'phone_number' => '202-555-0123'])) === null, 'US accepted');
    check(Contact::validationError(array_replace($base, ['real_name' => '王小明', 'phone_country_code' => '+86', 'phone_number' => '13800000000'])) === null, 'CN accepted');
    $italy = array_replace($base, ['phone_country_code' => '+39', 'phone_number' => '0612345678']);
    check(Contact::validationError($italy) === null && Contact::normalize($italy)['phone_number'] === '0612345678', 'Italy significant zero preserved');
    check(Contact::validationError(array_replace($base, ['wechat_id' => 'wxid_example123'])) === null, 'wxid is a warning, not rejection');
    foreach (['', '<script>alert(1)</script>', '1234', "홍\n길동", str_repeat('가', 81)] as $name) check(Contact::validationError(array_replace($base, ['real_name' => $name])) !== null, 'Invalid name refused');
    foreach (['+999', '+082', '82', '+0'] as $code) check(Contact::validationError(array_replace($base, ['phone_country_code' => $code])) !== null, 'Unknown code refused');
    foreach (['0000000000', 'hello', '+821012345678', '12345678901234567890123456'] as $phone) check(Contact::validationError(array_replace($base, ['phone_number' => $phone])) !== null, 'Invalid phone refused');
    foreach (['consent_terms', 'consent_privacy'] as $field) check(Contact::validationError(array_replace($base, [$field => false])) === 'CONTACT_CONSENT_REQUIRED', 'Missing consent refused');
    check(Contact::validationError(array_replace($base, ['contact_version' => 'old'])) === 'CONTACT_VERSION_MISMATCH', 'Old policy refused');
    check(Contact::validationError(array_replace($base, ['wechat_id' => ['admin']])) === 'CONTACT_REQUIRED', 'Array input refused');
    check(count(Contact::countries()) > 200, 'Calling codes not restricted to a few countries');
    foreach (Contact::countries() as $code => $country) check((bool)preg_match('/^\+[1-9][0-9]{0,2}$/D', $code) && (bool)preg_match('/^[A-Z]{2}$/D', $country['region']), 'Metadata entry well formed');

    DB::$pdo = new \PDO('sqlite::memory:');
    DB::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    DB::$pdo->exec('CREATE TABLE v2_user (id INTEGER PRIMARY KEY); CREATE TABLE v2_user_contact (user_id INTEGER PRIMARY KEY, real_name TEXT, wechat_id TEXT, phone_country_code TEXT, phone_number TEXT, consent_version TEXT, marketing_consent INTEGER, consented_at INTEGER, created_at INTEGER)');
    DB::$pdo->beginTransaction(); DB::$pdo->exec('INSERT INTO v2_user (id) VALUES (1)'); Contact::record(1, Contact::normalize($base), 100); DB::$pdo->commit();
    $record = DB::$pdo->query('SELECT * FROM v2_user_contact WHERE user_id=1')->fetch(\PDO::FETCH_ASSOC);
    check($record['phone_number'] === '1012345678' && $record['consent_version'] === Contact::VERSION && $record['consented_at'] === 100, 'Record persists normalized contact and consent');
    check(!array_key_exists('password', $record) && !array_key_exists('email', $record), 'No password/duplicate email stored');
    DB::$fail = true; DB::$pdo->beginTransaction(); DB::$pdo->exec('INSERT INTO v2_user (id) VALUES (2)');
    try { Contact::record(2, Contact::normalize($base), 101); throw new \LogicException('False insert was ignored'); } catch (\RuntimeException $e) { DB::$pdo->rollBack(); }
    check(DB::$pdo->query('SELECT COUNT(*) FROM v2_user WHERE id=2')->fetchColumn() === 0, 'Failed contact write rolls back user in transaction');
    echo "PASS: $checks contact validation, metadata and SQLite storage checks. HTTP/Laravel runtime is not exercised by this fixture.\n";
}
