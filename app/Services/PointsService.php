<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\PointsLedger;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN RC: referral reward POINTS (RC_CONTRACTS.md §4).
 *
 * Points are NOT currency. v2_user.balance and v2_user.commission_balance are never read or written here -
 * both are implicit CNY cash with no expiry concept, and the existing commission history is left untouched.
 * The display unit is "P"; a frontend must never convert points to KRW or CNY.
 *
 * Trigger: the invitee's FIRST COMPLETED PAID order. Order currency is irrelevant - a KRW order and a CNY
 * order grant the same number of points, which is why the amount is never a percentage of what was paid
 * (a percentage would be currency-dependent and points are currency-independent).
 *
 * Amount (OWNER_DECISIONS.md §F-2 / OD-3): the reward is worth the 30-DAY price of the plan the INVITER is
 * currently on - 30d ¥30 earns 30 P, BASIC 30d ¥45 earns 45 P. See referralPointsFor().
 *
 * Exactly once per invitee, guaranteed by the DATABASE: v2_referral_reward.invitee_user_id is UNIQUE, and the
 * grant is an insertOrIgnore. Two concurrent first-orders therefore cannot both grant, with no read-then-write
 * window to lose. The reward row and its ledger row are written in one transaction.
 *
 * Granting never breaks provisioning: grantForOrder() catches everything and logs. A points problem must not
 * roll back a paid order that has already been provisioned.
 *
 * Policy is admin-managed (three v2_settings rows, read directly from the database at grant time like
 * TrialService does, not through cached admin_setting()). RC scope is accrual + query + admin history.
 * SPENDING points is deliberately out of scope - PointsLedger::TYPE_ORDER_SPEND exists so the ledger shape does
 * not have to change later, but nothing in this round writes it.
 */
class PointsService
{
    public const KEYS = [
        'enable' => 'manduvpn_points_enable',
        'referral_reward' => 'manduvpn_points_referral_reward',
        'expire_days' => 'manduvpn_points_expire_days',
    ];
    /**
     * Disabled by default: no points are granted until an admin turns it on.
     * `referral_reward` is the FALLBACK amount only - the normal amount comes from the inviter's plan
     * (referralPointsFor()). It is used when the inviter has no plan with a 30-day price.
     */
    public const DEFAULTS = ['enable' => '0', 'referral_reward' => '1000', 'expire_days' => ''];
    /** How the normal reward amount is derived. Informational: the admin UI must not label it a flat amount. */
    public const REWARD_BASIS = 'inviter_plan_30d_price';
    /** The 30-day period keys, in the order they are tried. 'monthly' is the legacy plans' 30-day period. */
    public const REWARD_BASIS_PERIODS = [Plan::PERIOD_DAYS_30, Plan::PERIOD_MONTHLY];
    public const LIMITS = ['referral_reward' => [1, 1000000], 'expire_days' => [1, 3650]];
    /** Guard rail for a manual admin correction, so a typo cannot mint a huge balance. */
    public const ADJUST_LIMIT = 1000000;

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

        $v = $int($in['referral_reward'] ?? null);
        [$lo, $hi] = self::LIMITS['referral_reward'];
        if ($v === null || $v < $lo || $v > $hi) { $errors[] = "referral_reward must be an integer $lo..$hi points"; $p['referral_reward'] = null; } else { $p['referral_reward'] = $v; }

        // Empty = points never expire. That is a policy choice, not a missing value.
        if ($empty($in['expire_days'] ?? null)) {
            $p['expire_days'] = null;
        } else {
            $v = $int($in['expire_days']);
            [$lo, $hi] = self::LIMITS['expire_days'];
            if ($v === null || $v < $lo || $v > $hi) { $errors[] = "expire_days must be empty (never expires) or an integer $lo..$hi"; $p['expire_days'] = null; } else { $p['expire_days'] = $v; }
        }
        return [$p, $errors];
    }

    /** Current validated policy: [policy, errors]. */
    public static function policy(): array
    {
        return self::validate(self::rawPolicy());
    }

    /**
     * Balance = SUM of the ledger rows that have not expired (RC_CONTRACTS.md §4).
     * A row with expires_at NULL never expires. No background job is needed for this to be correct.
     */
    public static function balanceOf(int $userId, ?int $now = null): int
    {
        $now = $now ?? time();
        return (int) PointsLedger::where('user_id', $userId)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->sum('amount');
    }

    /** Points that have passed their expiry, for display ("expired" is informational, not part of the balance). */
    public static function expiredTotalOf(int $userId, ?int $now = null): int
    {
        $now = $now ?? time();
        return (int) PointsLedger::where('user_id', $userId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->sum('amount');
    }

    /** Ledger rows for a user, newest first. */
    public static function ledgerOf(int $userId, int $limit = 100): array
    {
        return PointsLedger::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->toArray();
    }

    /**
     * How many points one referral reward is worth (OWNER_DECISIONS.md §F-2 / OD-3): the 30-day list price of
     * the plan the INVITER is currently on. 1 P == ¥1 of list price, so 30d ¥30 -> 30 P and BASIC 30d ¥45 -> 45 P.
     *
     * The CNY price is the basis, never prices_krw. The two are INDEPENDENT list prices (prices is in yuan,
     * prices_krw is in won), so reading prices_krw would make the same referral worth ~200x more depending on
     * which column happened to be filled in. Every sellable plan/period carries a CNY price - that is exactly
     * what Owner decision A on OD-1 guarantees and what `manduvpn:plan-data validate` enforces - so the CNY
     * price is defined for every plan a user can actually be on.
     *
     * Fallbacks, in order, all of which land on the admin-configured flat `referral_reward`:
     *   - the inviter has no plan_id (never subscribed, or the subscription was cleared),
     *   - the plan row is gone,
     *   - the plan has no 30-day price (a 360-day-only plan, or a reset-traffic-only price map).
     * That flat value is the pre-existing setting, kept so a grant is never silently skipped or zero.
     *
     * The ceiling is the existing LIMITS['referral_reward'] range - no new setting, no new cap. The trigger
     * (the invitee's first completed paid order) and the TTL (expire_days) are unchanged.
     */
    public static function referralPointsFor(User $inviter, array $policy): int
    {
        $fallback = (int) $policy['referral_reward'];
        [$lo, $hi] = self::LIMITS['referral_reward'];
        $planId = $inviter->plan_id ? (int) $inviter->plan_id : null;
        if ($planId === null) {
            return $fallback;
        }
        $plan = Plan::find($planId);
        if (!$plan) {
            return $fallback;
        }
        $prices = is_array($plan->prices) ? $plan->prices : [];
        foreach (self::REWARD_BASIS_PERIODS as $period) {
            if (!isset($prices[$period]) || !is_numeric($prices[$period])) {
                continue;
            }
            // Yuan may be stored with decimals (e.g. 29.9). Points are whole numbers, so round rather than
            // truncate: 29.9 is worth 30 P, not 29 P.
            $points = (int) round((float) $prices[$period]);
            if ($points < $lo) {
                // A zero or negative 30-day price is a free/broken plan, not a reward basis.
                continue;
            }
            return min($points, $hi);
        }
        return $fallback;
    }

    /**
     * Called from OrderService::open() inside the order transaction. Never throws: a paid order that has already
     * been provisioned must not be rolled back because of a points problem.
     * Returns true only when this call actually granted.
     */
    public static function grantForOrder(Order $order, User $invitee): bool
    {
        try {
            $raw = self::rawPolicy();
            if ($raw['enable'] !== '1') {
                return false;
            }
            [$policy, $errors] = self::validate($raw);
            if ($errors) {
                Log::warning('ManduVPN RC: referral points not granted, invalid policy', [
                    'user_id' => (int) $invitee->id, 'errors' => count($errors),
                ]);
                return false;
            }
            $inviterId = $invitee->invite_user_id ? (int) $invitee->invite_user_id : null;
            if ($inviterId === null || $inviterId === (int) $invitee->id) {
                return false;
            }
            // "Paid": the order actually cost money. A fully discounted / surplus-covered order does not earn a
            // referral reward. Currency is irrelevant - points are currency-independent.
            if ((int) $order->total_amount <= 0) {
                return false;
            }
            $inviter = User::find($inviterId);
            if (!$inviter) {
                return false;
            }
            // OD-3: the amount is the inviter's own plan's 30-day price, not the invitee's order amount.
            $points = self::referralPointsFor($inviter, $policy);
            return self::grant($inviterId, (int) $invitee->id, (int) $order->id, $policy, time(), $points);
        } catch (\Throwable $e) {
            Log::warning('ManduVPN RC: referral points grant failed', [
                'user_id' => (int) $invitee->id, 'reason' => class_basename($e),
            ]);
            return false;
        }
    }

    /**
     * Atomic, exactly-once grant. The UNIQUE index on invitee_user_id does the deduplication, so the ledger row
     * is written only by the call whose insert actually created the reward row.
     *
     * $points overrides the amount (grantForOrder passes the OD-3 plan-derived value). Omitting it falls back to
     * the flat policy amount, which is what a direct admin-side or test call gets.
     */
    public static function grant(int $inviterId, int $inviteeId, ?int $orderId, array $policy, int $now, ?int $points = null): bool
    {
        $points = $points ?? (int) $policy['referral_reward'];
        $expiresAt = $policy['expire_days'] === null ? null : $now + (int) $policy['expire_days'] * 86400;

        return (bool) DB::transaction(function () use ($inviterId, $inviteeId, $orderId, $points, $expiresAt, $now) {
            $inserted = DB::table('v2_referral_reward')->insertOrIgnore([
                'inviter_user_id' => $inviterId,
                'invitee_user_id' => $inviteeId,
                'trigger_order_id' => $orderId,
                'points' => $points,
                'status' => ReferralReward::STATUS_GRANTED,
                'granted_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                // Already rewarded for this invitee (or a concurrent call won). Nothing further to do.
                return false;
            }
            $rewardId = (int) DB::table('v2_referral_reward')->where('invitee_user_id', $inviteeId)->value('id');
            DB::table('v2_points_ledger')->insert([
                'user_id' => $inviterId,
                'amount' => $points,
                'type' => PointsLedger::TYPE_REFERRAL_REWARD,
                'source_type' => 'referral_reward',
                'source_id' => $rewardId,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);
            Log::info('ManduVPN RC: referral points granted', [
                'inviter_user_id' => $inviterId, 'invitee_user_id' => $inviteeId, 'points' => $points,
            ]);
            return true;
        });
    }

    /**
     * Admin correction. Appends a signed ledger row - it never edits or deletes history. Returns the new balance.
     * Revoking a reward is a separate action (revokeReward) because it also flips the reward row's status.
     */
    public static function adjust(int $userId, int $amount, ?int $expireDays, int $adminId, ?int $now = null): int
    {
        $now = $now ?? time();
        if ($amount === 0 || abs($amount) > self::ADJUST_LIMIT) {
            throw new \InvalidArgumentException('amount must be a non-zero integer within +/-' . self::ADJUST_LIMIT);
        }
        $expiresAt = ($amount > 0 && $expireDays !== null) ? $now + $expireDays * 86400 : null;
        DB::table('v2_points_ledger')->insert([
            'user_id' => $userId,
            'amount' => $amount,
            'type' => PointsLedger::TYPE_ADMIN_ADJUST,
            'source_type' => 'admin',
            'source_id' => $adminId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
        Log::info('ManduVPN RC: points adjusted by admin', ['user_id' => $userId, 'admin_id' => $adminId, 'amount' => $amount]);
        return self::balanceOf($userId, $now);
    }

    /**
     * Revokes a granted reward: flips the reward row to revoked and appends the offsetting ledger row.
     * The conditional update means a second revoke changes nothing and writes no second offset.
     */
    public static function revokeReward(int $rewardId, int $adminId, ?int $now = null): bool
    {
        $now = $now ?? time();
        return (bool) DB::transaction(function () use ($rewardId, $adminId, $now) {
            $reward = DB::table('v2_referral_reward')->where('id', $rewardId)->first();
            if (!$reward || $reward->status !== ReferralReward::STATUS_GRANTED) {
                return false;
            }
            $changed = DB::table('v2_referral_reward')
                ->where('id', $rewardId)
                ->where('status', ReferralReward::STATUS_GRANTED)
                ->update(['status' => ReferralReward::STATUS_REVOKED, 'updated_at' => $now]);
            if ($changed !== 1) {
                return false;
            }
            DB::table('v2_points_ledger')->insert([
                'user_id' => (int) $reward->inviter_user_id,
                'amount' => -1 * (int) $reward->points,
                'type' => PointsLedger::TYPE_ADMIN_ADJUST,
                'source_type' => 'referral_reward_revoked',
                'source_id' => $rewardId,
                'expires_at' => null,
                'created_at' => $now,
            ]);
            Log::info('ManduVPN RC: referral reward revoked', ['reward_id' => $rewardId, 'admin_id' => $adminId]);
            return true;
        });
    }

    /** Rewards an inviter has earned, newest first (user-facing referral screen and admin detail). */
    public static function rewardsByInviter(int $inviterId, int $limit = 100): array
    {
        return ReferralReward::where('inviter_user_id', $inviterId)
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->toArray();
    }
}
