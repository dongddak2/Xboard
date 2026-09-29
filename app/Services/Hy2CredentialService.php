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
 * exposure of a Hysteria2 password used to be permanent exposure. This credential expires.
 *
 * ★ WHERE EXPIRY IS ENFORCED: IN THIS CODEBASE, NOT BY THE NODE'S USER LIST. The panel is the authoritative
 * enforcement point and the node list is only the PROPAGATION mechanism. That is a design decision, and it is
 * also the measured truth: on a pull where the node's own info changed, V2bX calls DelNode -> AddNode ->
 * AddUsers(all) without DelUsers (node/task.go:76-160), and AddUsers only ADDS to usersMap without clearing it
 * (core/hy2/user.go:29-42), so a user dropped from the list in that same pull keeps a usable value until the
 * process restarts - and the next comparison uses the replaced list as its baseline, so that user is never seen
 * as deleted again. Therefore "the panel stopped listing the row" does NOT mean "the credential is dead", and
 * every path that hands out or authorises a credential checks expires_at itself: the profile endpoint (which
 * renews, so it can only ever serve a live value) and POST /vpn/sessions (which refuses a lapsed one).
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
     * ★THE ADOPTED LIFETIME: 24 HOURS★ (LEAD, RC_CONTRACTS §23.62). The authority is the setting below; this is
     * the value used when no setting has been written. THE NAME IS KEPT UNCHANGED ON INSTRUCTION so that nothing
     * else has to move - read it as "the code-side default", not as "not yet decided", because 86400 IS the
     * decided value.
     *
     * THE FOUR GROUNDS, at the same place as the number:
     *  1. Three orders of magnitude above the propagation floor (the node's pull_interval, default 60s), so no
     *     credential can lapse anywhere near the moment it is being propagated.
     *  2. ★The decisive one: because a read RENEWS (see "READ RENEWS" above), this is effectively rolling.★ Every
     *     profile or subscription fetch pushes the expiry out, so an active user never reaches it. What the
     *     expiry actually bites is a credential that has not been read for 24 hours - which is exactly the
     *     exposure this table exists to close.
     *  3. The exposure goes from "permanent, revocable only by rotating the subscription identity" to "one day".
     *     That is enough for the purpose.
     *  4. Changing it costs one setting and no code, so starting generous and tightening is the cheap direction;
     *     starting tight and discovering breakage in the field is the expensive one.
     *
     * STATED PLAINLY: any finite lifetime breaks a third-party client that caches a subscription for longer than
     * it. 86400 reduces that, it does not remove it; the client recovers by re-fetching, and the knob if field
     * reports arrive is the setting, not this constant.
     *
     * The RC suite forces expiry by writing expires_at into the past inside its rolled-back transaction, so no
     * test result depends on this number and no test writes a setting.
     */
    public const TTL_SECONDS_PROVISIONAL_NOT_ADOPTED = 86400;

    public const TTL_SETTING_KEY = 'manduvpn_hy2_credential_ttl_seconds';

    /** Per-request memo, so one subscription build with several hysteria nodes writes the row once. */
    private static array $memo = [];

    /** The lifetime in seconds: the setting if one is written, otherwise the adopted 24 hours. Grounds: see the constant. */
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
     * This user's credential ONLY IF it is still live, without issuing or renewing anything. This is the check
     * an authorisation path uses (POST /vpn/sessions): null means "lapsed or never issued", and the caller must
     * refuse rather than assume the node will. See the note at the top of this class for why the node's list is
     * not a safe place to enforce expiry.
     */
    public static function liveSecret(int $userId): ?string
    {
        return self::liveSecrets([$userId])[$userId] ?? null;
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
     * credential is DROPPED, so the node is never handed the uuid as a fallback - a fallback would make the
     * expiry meaningless. They come back on their next profile or subscription fetch.
     *
     * DROPPING IS PROPAGATION, NOT ENFORCEMENT. The node may keep a dropped value in memory (see the class
     * note), so nothing here may be read as "the credential has been revoked". Enforcement lives in the
     * authorisation paths that call liveSecret().
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
