<?php

namespace App\Services;

use App\Models\Hy2Credential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN HY2-CRED-1 (RC_CONTRACTS §23.57, candidate B): the single place that issues, reads, renews and
 * invalidates a user's short-lived Hysteria2 credential.
 *
 * WHY A CREDENTIAL AND NOT v2_user.uuid. The uuid never expires, and it is also the subscription identity, so
 * exposure of a Hysteria2 password used to be permanent exposure. This credential expires, and expiry is
 * enforced at the only place that matters - the node's user list: an expired row is not served, so the node
 * does not know the value and the handshake is refused.
 *
 * ONE VALUE PER USER. The node list carries one credential per user, so that is the unit. There is no overlap
 * window (old and new value live at once) because V2bX accepting two values for one user is unmeasured.
 *
 * READ RENEWS, IT DOES NOT ROTATE. Every read (profile fetch, subscription fetch) pushes expires_at out to
 * now + TTL and keeps the existing secret while it is still live. Two consequences, both deliberate:
 *   - the profile-fetch -> handshake race cannot happen: what a client has just been handed always has a full
 *     TTL of life ahead of it, so the value cannot lapse between fetching it and using it;
 *   - the node's ETag is stable across renewals (only expires_at moves, and that is not in the node payload),
 *     so renewal does not force every node to re-download the user list.
 * A NEW secret is created only when there is no row, or the row has already expired.
 *
 * PROPAGATION IS BOUNDED BELOW BY THE NODE PULL. A freshly issued secret reaches the node on its next
 * /UniProxy/user pull (base_config.pull_interval, default 60s). So a client that fetches a profile in the first
 * moments after a new secret was created can be refused until that pull happens. This is the same lower bound
 * that already governs ban / expiry / quota propagation - it is not specific to this credential.
 *
 * SECRET HANDLING: 32 random [A-Za-z0-9] (CSPRNG, ~190 bits), alphanumeric only so the value is safe inside a
 * hysteria2:// URI and in a node config with no quoting or escaping. Stored ONLY as Crypt::encryptString
 * (APP_KEY, AES-256-CBC + MAC). Plaintext exists in a return value and nowhere else: never logged, never in an
 * exception message, never in an error response.
 */
class Hy2CredentialService
{
    private const SECRET_LEN = 32;
    private const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public const SECRET_PATTERN = '/^[A-Za-z0-9]{32}$/';

    /**
     * THIS IS NOT THE ADOPTED TTL. The value is deliberately undecided (LEAD, §23.57 condition 6): the lower
     * bound depends on two unmeasured node-side facts - whether an already-established tunnel survives the
     * node's user list changing, and the node's own pull cadence - and INFRA is measuring them. This fallback
     * exists only so the path is runnable before the value is fixed; the adopted value will arrive as
     * admin_setting('manduvpn_hy2_credential_ttl_seconds') and nothing in this class needs to change for it.
     * The RC suite forces expiry by writing expires_at into the past inside its rolled-back transaction, so no
     * test result depends on this number and no test writes a setting.
     */
    public const TTL_SECONDS_PROVISIONAL_NOT_ADOPTED = 3600;

    public const TTL_SETTING_KEY = 'manduvpn_hy2_credential_ttl_seconds';

    /** Per-request memo, so one subscription build with several hysteria nodes writes the row once. */
    private static array $memo = [];

    /** The configured lifetime in seconds. See TTL_SECONDS_PROVISIONAL_NOT_ADOPTED before reading a meaning into it. */
    public static function ttlSeconds(): int
    {
        $ttl = (int) admin_setting(self::TTL_SETTING_KEY, self::TTL_SECONDS_PROVISIONAL_NOT_ADOPTED);
        return $ttl > 0 ? $ttl : self::TTL_SECONDS_PROVISIONAL_NOT_ADOPTED;
    }

    /**
     * The plaintext credential this user should present to a hysteria node right now, issuing or renewing as
     * needed. Accepts a User model or a plain user array/row (the subscription builders hold an array).
     * Returns null only when the caller passed something with no user id.
     */
    public static function password($user): ?string
    {
        $userId = self::userIdOf($user);
        if ($userId === null) {
            return null;
        }
        if (isset(self::$memo[$userId])) {
            return self::$memo[$userId];
        }
        return self::$memo[$userId] = self::issueOrRenew($userId);
    }

    /**
     * The live credentials for these user ids, as [user_id => plaintext]. An expired or missing row is simply
     * absent - this method never issues, because it is called on the node pull and a pull must not write a row
     * for every user in the system.
     */
    public static function liveSecrets(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $out = [];
        Hy2Credential::whereIn('user_id', $userIds)
            ->where('expires_at', '>', time())
            ->get(['user_id', 'secret_enc'])
            ->each(function (Hy2Credential $cred) use (&$out) {
                $out[(int) $cred->user_id] = Crypt::decryptString($cred->secret_enc);
            });
        return $out;
    }

    /**
     * The node's user list with the hysteria password in place of v2_user.uuid. A user without a live
     * credential is DROPPED: the node must not be able to authenticate them with the uuid, which is exactly
     * what "the credential expires" means. They come back on their next profile or subscription fetch.
     *
     * ponytail: one Crypt::decryptString per listed user per pull. At the sizes this panel runs, that is
     * cheaper than the pull's own JSON encoding; if it ever shows up in a profile, cache the decrypted list
     * under the ETag rather than reaching for a reversible-but-unencrypted column.
     */
    public static function applyToNodeUsers(Collection $users): Collection
    {
        $secrets = self::liveSecrets($users->pluck('id')->map(fn ($id) => (int) $id)->all());
        return $users
            ->filter(fn ($row) => isset($secrets[(int) $row->id]))
            ->each(function ($row) use ($secrets) {
                $row->uuid = $secrets[(int) $row->id];
            })
            ->values();
    }

    /**
     * THE ONE INVALIDATION POINT. Deletes the credential so the next node pull stops accepting it.
     *
     * Every uuid rotation path must end up here, and they do without calling it themselves: User::booted()
     * fires this whenever v2_user.uuid actually changes, which covers V1/User/UserController@resetSecurity,
     * V2/Admin/UserController@resetSecret and the console reset:user - all three assign uuid on a User model
     * and save it. Copying a call into each of the three would be three places to forget; the model event is
     * one. A path that bypasses Eloquent (a mass ->update() on v2_user) would bypass this too, which is why
     * the RC suite asserts the shape of all three sites, not only the event.
     */
    public static function invalidate(int $userId): bool
    {
        unset(self::$memo[$userId]);
        $deleted = Hy2Credential::where('user_id', $userId)->delete() > 0;
        if ($deleted) {
            Log::info('ManduVPN Hysteria2 credential invalidated', ['user_id' => $userId]);
        }
        return $deleted;
    }

    /** Test seam: forget the per-request memo. Nothing in production needs it. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    private static function issueOrRenew(int $userId): string
    {
        $now = time();
        $cred = Hy2Credential::find($userId);
        if ($cred && (int) $cred->expires_at > $now) {
            // Same secret, more life. See "READ RENEWS, IT DOES NOT ROTATE" above.
            $cred->forceFill(['expires_at' => $now + self::ttlSeconds()])->save();
            return Crypt::decryptString($cred->secret_enc);
        }
        $secret = self::random(self::SECRET_LEN);
        $row = $cred ?: new Hy2Credential();
        $row->forceFill([
            'user_id' => $userId,
            'secret_enc' => Crypt::encryptString($secret),
            'expires_at' => $now + self::ttlSeconds(),
            'issued_at' => $now,
        ]);
        try {
            $row->save();
        } catch (QueryException $e) {
            // Concurrent first issue for the same user: the winner's row is the live one.
            $raced = Hy2Credential::find($userId);
            if (!$raced) {
                throw $e;
            }
            return Crypt::decryptString($raced->secret_enc);
        }
        Log::info('ManduVPN Hysteria2 credential issued', ['user_id' => $userId, 'expires_at' => (int) $row->expires_at]);
        return $secret;
    }

    private static function userIdOf($user): ?int
    {
        $id = is_array($user) ? ($user['id'] ?? null) : ($user->id ?? null);
        return $id === null ? null : (int) $id;
    }

    private static function random(int $length): string
    {
        $max = strlen(self::ALNUM) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALNUM[random_int(0, $max)];
        }
        return $out;
    }
}
