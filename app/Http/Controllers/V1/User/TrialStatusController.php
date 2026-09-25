<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ManduVPN TR-2A: read-only trial status of the authenticated user (GET /api/v1/user/manduvpn/trial).
 *
 * State priority: paid (plan_id set, whatever its expiry or trial history) > none (never granted) >
 * expired (expired_at <= now) > exhausted (u + d >= transfer_enable) > active.
 * paid / none return only {state, trial_used}; trial states return the snapshot fields listed in describe().
 * Corrupt rows never read as active: a NULL expired_at on a granted plan-less row is expired, a negative u or d is
 * exhausted with remaining_bytes 0. Never writes.
 */
class TrialStatusController extends Controller
{
    public function fetch(Request $request)
    {
        $row = DB::table('v2_user')->where('id', $request->user()->id)
            ->first(['plan_id', 'trial_granted_at', 'expired_at', 'u', 'd', 'transfer_enable', 'device_limit', 'speed_limit']);
        if (!$row) {
            return $this->fail([400, __('The user does not exist')]);
        }
        return $this->success(self::describe((array) $row, now()->getTimestamp()));
    }

    public static function describe(array $u, int $now): array
    {
        $trialUsed = $u['trial_granted_at'] !== null;
        if ($u['plan_id'] !== null) {
            return ['state' => 'paid', 'trial_used' => $trialUsed];
        }
        if (!$trialUsed) {
            return ['state' => 'none', 'trial_used' => false];
        }
        $up = (int) $u['u'];
        $down = (int) $u['d'];
        $total = (int) $u['transfer_enable'];
        $corrupt = $up < 0 || $down < 0;
        $used = max($up, 0) + max($down, 0);
        $expires = $u['expired_at'] === null ? null : (int) $u['expired_at'];
        if ($expires === null || $expires <= $now) {
            $state = 'expired';
        } elseif ($corrupt || $used >= $total) {
            $state = 'exhausted';
        } else {
            $state = 'active';
        }
        return [
            'state' => $state,
            'trial_used' => true,
            'granted_at' => (int) $u['trial_granted_at'],
            'expires_at' => $expires,
            'transfer_enable' => $total,
            'used_bytes' => $used,
            'remaining_bytes' => $corrupt ? 0 : max($total - $used, 0),
            'device_limit' => $u['device_limit'] === null ? null : (int) $u['device_limit'],
            'speed_limit' => $u['speed_limit'] === null ? null : (int) $u['speed_limit'],
        ];
    }
}
