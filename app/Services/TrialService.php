<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN TR-1b: one-time free trial on a successful self-service signup, driven by an admin-managed policy.
 *
 * Policy = six v2_settings rows (manduvpn_trial_*), read directly from the database at grant time (no cached
 * admin_setting()) and validated by the same rules the admin API uses. The grant SNAPSHOTS the policy onto v2_user
 * (group_id, transfer_enable, device_limit, speed_limit, expired_at): later policy changes never alter existing users.
 *
 * The trial is a separate entitlement: plan_id stays NULL, assigned_server_id is never touched and the BASIC
 * assignment service is never called, so the existing group rules (PP-C1) decide which shown servers the user sees.
 * No order, payment, plan or VPN credential. Referral and partner status are not read.
 * Exactly once: one conditional UPDATE ... WHERE trial_granted_at IS NULL AND plan_id IS NULL in a transaction; the
 * evidence column is never cleared, so expiry, quota use, purchases, policy edits or toggling never allow a re-grant.
 * grantOnSignup() never throws: signup must never fail because of the trial.
 */
class TrialService
{
    public const KEYS = [
        'enable' => 'manduvpn_trial_enable',
        'duration_hours' => 'manduvpn_trial_duration_hours',
        'transfer_gb' => 'manduvpn_trial_transfer_gb',
        'device_limit' => 'manduvpn_trial_device_limit',
        'speed_limit' => 'manduvpn_trial_speed_limit',
        'group_id' => 'manduvpn_trial_group_id',
    ];
    public const DEFAULTS = ['enable' => '0', 'duration_hours' => '24', 'transfer_gb' => '10', 'device_limit' => '1', 'speed_limit' => '', 'group_id' => ''];
    public const GIB = 1073741824;
    public const LIMITS = ['duration_hours' => [1, 720], 'transfer_gb' => [1, 1000], 'device_limit' => [1, 10], 'speed_limit' => [1, 100000]];

    /** Stored values as strings (a missing row reads as its default). */
    public static function rawPolicy(): array
    {
        $rows = DB::table('v2_settings')->whereIn('name', array_values(self::KEYS))->pluck('value', 'name')->all();
        $out = [];
        foreach (self::KEYS as $k => $name) {
            $out[$k] = array_key_exists($name, $rows) ? (string) ($rows[$name] ?? '') : self::DEFAULTS[$k];
        }
        return $out;
    }

    /** Strict validation shared by the grant and the admin API. Returns [normalized policy, list of errors]. */
    public static function validate(array $in): array
    {
        $errors = [];
        $int = function ($v) {
            if (is_int($v)) return $v;
            if (is_string($v) && preg_match('/^[0-9]{1,9}$/', $v)) return (int) $v;
            return null;
        };
        $empty = fn ($v) => $v === null || $v === '';
        $p = [];
        $e = $in['enable'] ?? null;
        if (in_array($e, [true, 1, '1'], true)) $p['enable'] = true;
        elseif (in_array($e, [false, 0, '0'], true)) $p['enable'] = false;
        else { $errors[] = 'enable must be true/false'; $p['enable'] = false; }
        foreach (['duration_hours', 'transfer_gb', 'device_limit'] as $k) {
            $v = $int($in[$k] ?? null);
            [$lo, $hi] = self::LIMITS[$k];
            if ($v === null || $v < $lo || $v > $hi) { $errors[] = "$k must be an integer $lo..$hi"; $p[$k] = null; } else { $p[$k] = $v; }
        }
        if ($empty($in['speed_limit'] ?? null)) {
            $p['speed_limit'] = null;
        } else {
            $v = $int($in['speed_limit']);
            [$lo, $hi] = self::LIMITS['speed_limit'];
            if ($v === null || $v < $lo || $v > $hi) { $errors[] = "speed_limit must be empty (unlimited) or an integer $lo..$hi Mbps"; $p['speed_limit'] = null; } else { $p['speed_limit'] = $v; }
        }
        $groupError = 'group_id must be an existing server group';
        if ($empty($in['group_id'] ?? null)) {
            $p['group_id'] = null;
        } else {
            $v = $int($in['group_id']);
            if ($v === null || $v < 1 || !DB::table('v2_server_group')->where('id', $v)->exists()) { $errors[] = $groupError; $p['group_id'] = null; } else { $p['group_id'] = $v; }
        }
        if ($p['enable'] && $p['group_id'] === null && !in_array($groupError, $errors, true)) {
            $errors[] = 'enabling the trial requires a server group';
        }
        return [$p, $errors];
    }

    /** Current validated policy: [policy, errors]. */
    public static function policy(): array
    {
        return self::validate(self::rawPolicy());
    }

    public static function shownNodeCount(int $groupId): int
    {
        return Server::whereJsonContains('group_ids', $groupId)->where('show', true)->count();
    }

    /** True when this call granted the trial. Never throws. */
    public static function grantOnSignup(User $user): bool
    {
        try {
            $raw = self::rawPolicy();
            if ($raw['enable'] !== '1') {
                return false;
            }
            [$policy, $errors] = self::validate($raw);
            if ($errors) {
                Log::warning('ManduVPN TR-1b trial not granted: invalid trial policy', ['user_id' => (int) $user->id, 'errors' => count($errors)]);
                return false;
            }
            $granted = self::grant((int) $user->id, $policy, time());
            if ($granted) {
                $user->refresh();
            }
            return $granted;
        } catch (\Throwable $e) {
            Log::warning('ManduVPN TR-1b trial not granted: grant failed', ['user_id' => (int) $user->id, 'reason' => class_basename($e)]);
            return false;
        }
    }

    /** Atomic, exactly-once grant that snapshots $policy. True only for the call that set trial_granted_at. */
    public static function grant(int $userId, array $policy, int $now): bool
    {
        $changed = DB::transaction(fn () => DB::table('v2_user')
            ->where('id', $userId)
            ->whereNull('trial_granted_at')
            ->whereNull('plan_id')
            ->update([
                'group_id' => (int) $policy['group_id'],
                'transfer_enable' => (int) $policy['transfer_gb'] * self::GIB,
                'u' => 0,
                'd' => 0,
                'device_limit' => (int) $policy['device_limit'],
                'speed_limit' => $policy['speed_limit'] === null ? null : (int) $policy['speed_limit'],
                'expired_at' => $now + (int) $policy['duration_hours'] * 3600,
                'trial_granted_at' => $now,
                'updated_at' => $now,
            ]));
        if ($changed === 1) {
            Log::info('ManduVPN TR-1b trial granted', ['user_id' => $userId]);
            return true;
        }
        return false;
    }
}
