<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\VpnCredential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN PP-C4-1A: the single place that creates, reads, rotates and revokes a user's IKEv2 VPN credential.
 *
 * - username: "mv" + 10 random [a-z0-9] (CSPRNG), globally unique, stable across secret rotation.
 * - secret:   24 random [A-Za-z0-9] (CSPRNG, ~142 bits), stored ONLY as Crypt::encryptString (APP_KEY, AES-256-CBC
 *             + MAC). Plaintext exists only in the return value of get()/rotate(); it is never logged or persisted.
 * - ensure(): idempotent; creates only when explicitly called (no bulk / scheduled creation).
 * - revoke(): deletes the row. A later ensure() issues a completely NEW username + secret (full reset).
 * Charset is alphanumeric only, so values are safe in strongSwan ipsec.secrets without quoting/escaping.
 */
class VpnCredentialService
{
    private const USERNAME_PREFIX = 'mv';
    private const USERNAME_RANDOM_LEN = 10;
    private const SECRET_LEN = 24;
    private const LOWER_DIGITS = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public const USERNAME_PATTERN = '/^mv[a-z0-9]{10}$/';
    public const SECRET_PATTERN = '/^[A-Za-z0-9]{24}$/';

    /** Returns the existing credential or creates one. Never returns or logs the plaintext. */
    public static function ensure(User $user): VpnCredential
    {
        $existing = VpnCredential::find($user->id);
        if ($existing) {
            return $existing;
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $cred = new VpnCredential();
            $cred->forceFill([
                'user_id' => (int) $user->id,
                'username' => self::USERNAME_PREFIX . self::random(self::LOWER_DIGITS, self::USERNAME_RANDOM_LEN),
                'secret_enc' => Crypt::encryptString(self::random(self::ALNUM, self::SECRET_LEN)),
                'rotated_at' => time(),
            ]);
            try {
                $cred->save();
                Log::info('ManduVPN VPN credential created', ['user_id' => (int) $user->id]);
                return $cred;
            } catch (QueryException $e) {
                // concurrent ensure() for the same user, or (astronomically unlikely) username collision
                if ($raced = VpnCredential::find($user->id)) {
                    return $raced;
                }
            }
        }
        throw new ApiException('VPN 계정 생성에 실패했습니다', 500);
    }

    /** Explicit plaintext read: ['username', 'secret', 'rotated_at'] or null when the user has no credential. */
    public static function get(User $user): ?array
    {
        $cred = VpnCredential::find($user->id);
        if (!$cred) {
            return null;
        }
        return ['username' => $cred->username, 'secret' => Crypt::decryptString($cred->secret_enc), 'rotated_at' => $cred->rotated_at];
    }

    /** New secret, same username; the old secret stops existing immediately. Returns the new plaintext set. */
    public static function rotate(User $user): array
    {
        $cred = VpnCredential::find($user->id);
        if (!$cred) {
            throw new ApiException('VPN 계정이 없습니다', 404);
        }
        $secret = self::random(self::ALNUM, self::SECRET_LEN);
        $cred->forceFill(['secret_enc' => Crypt::encryptString($secret), 'rotated_at' => time()])->save();
        Log::info('ManduVPN VPN credential rotated', ['user_id' => (int) $user->id]);
        return ['username' => $cred->username, 'secret' => $secret, 'rotated_at' => $cred->rotated_at];
    }

    /** Deletes the credential. Returns true when one existed. */
    public static function revoke(User $user): bool
    {
        $deleted = VpnCredential::where('user_id', $user->id)->delete() > 0;
        if ($deleted) {
            Log::info('ManduVPN VPN credential revoked', ['user_id' => (int) $user->id]);
        }
        return $deleted;
    }

    private static function random(string $alphabet, int $length): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
