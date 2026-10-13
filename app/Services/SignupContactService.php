<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SignupContactService
{
    public const VERSION = '2026-10-14.1';

    public static function countries(): array
    {
        static $countries;
        if ($countries === null) {
            $countries = json_decode(file_get_contents(__DIR__ . '/../../config/mandu-phone-countries.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        return $countries;
    }

    public static function normalize(array $input): array
    {
        $code = $input['phone_country_code'];
        $phone = str_replace([' ', '(', ')', '-'], '', $input['phone_number']);
        if (!empty(self::countries()[$code]['strip_zero']) && substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        return ['real_name' => trim($input['real_name']), 'wechat_id' => trim($input['wechat_id']), 'phone_country_code' => $code, 'phone_number' => $phone, 'marketing_consent' => in_array($input['consent_marketing'] ?? false, [true, 1, '1'], true) ? 1 : 0];
    }

    public static function validationError(array $input): ?string
    {
        foreach (['real_name', 'wechat_id', 'phone_country_code', 'phone_number'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                return 'CONTACT_REQUIRED';
            }
        }
        if (strlen($input['real_name']) > 320 || strlen($input['wechat_id']) > 64 || strlen($input['phone_country_code']) > 4 || strlen($input['phone_number']) > 25) {
            return 'CONTACT_TOO_LONG';
        }
        $contact = self::normalize($input);
        if (!preg_match('/^[\p{L}\p{M} .\x{2019}\x{00B7}\'-]{1,80}$/u', $contact['real_name']) || !preg_match('/\p{L}/u', $contact['real_name'])) {
            return 'CONTACT_NAME_INVALID';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $contact['wechat_id'])) {
            return 'CONTACT_WECHAT_INVALID';
        }
        if (!isset(self::countries()[$contact['phone_country_code']]) || !preg_match('/^[0-9 ()-]+$/D', $input['phone_number']) || !preg_match('/^[0-9]{4,14}$/D', $contact['phone_number']) || !preg_match('/[1-9]/', $contact['phone_number']) || !preg_match('/^\+[1-9][0-9]{4,14}$/D', $contact['phone_country_code'] . $contact['phone_number'])) {
            return 'CONTACT_PHONE_INVALID';
        }
        foreach (['consent_terms', 'consent_privacy'] as $field) {
            if (!in_array($input[$field] ?? null, [true, 1, '1'], true)) {
                return 'CONTACT_CONSENT_REQUIRED';
            }
        }
        if (isset($input['consent_marketing']) && !in_array($input['consent_marketing'], [true, false, 1, 0, '1', '0'], true)) {
            return 'CONTACT_CONSENT_REQUIRED';
        }
        return ($input['contact_version'] ?? null) === self::VERSION ? null : 'CONTACT_VERSION_MISMATCH';
    }

    public static function available(): bool
    {
        return Schema::hasTable('v2_user_contact') && Schema::hasColumns('v2_user_contact', ['user_id', 'real_name', 'wechat_id', 'phone_country_code', 'phone_number', 'consent_version', 'consented_at', 'created_at', 'marketing_consent']);
    }

    public static function publicPolicy(): array
    {
        return ['available' => self::available(), 'version' => self::VERSION];
    }

    /** Called within the existing user + consent DB transaction. Never serialized on User. */
    public static function record(int $userId, array $contact, int $now): void
    {
        $stored = DB::table('v2_user_contact')->insert([
            'user_id' => $userId,
            'real_name' => $contact['real_name'],
            'wechat_id' => $contact['wechat_id'],
            'phone_country_code' => $contact['phone_country_code'],
            'phone_number' => $contact['phone_number'],
            'consent_version' => self::VERSION,
            'marketing_consent' => $contact['marketing_consent'],
            'consented_at' => $now,
            'created_at' => $now,
        ]);
        if (!$stored) {
            throw new \RuntimeException('Signup contact could not be stored');
        }
    }
}
