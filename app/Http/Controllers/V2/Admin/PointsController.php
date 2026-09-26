<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PointsLedger;
use App\Services\PointsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN RC: admin side of referral POINTS (RC_CONTRACTS.md §4) - policy, reward history, per-user ledger,
 * manual correction and revocation. On the admin middleware via the route groups in AdminRoute.
 *
 * Saving the policy affects FUTURE grants only: a grant snapshots the amount and expiry onto its reward and
 * ledger rows, exactly like the trial policy does, so changing the amount never rewrites anyone's balance.
 *
 * Corrections are append-only: adjust() and revokeReward() add signed ledger rows and never edit or delete
 * history. Nothing here touches v2_user.balance or v2_user.commission_balance.
 */
class PointsController extends Controller
{
    public function fetchPolicy()
    {
        [$policy, $errors] = PointsService::policy();
        return $this->success($this->describe($policy, $errors));
    }

    public function savePolicy(Request $request)
    {
        $in = [];
        foreach (array_keys(PointsService::KEYS) as $k) {
            if (!$request->exists($k)) {
                throw new ApiException("all points policy fields are required (missing $k)", 422);
            }
            $in[$k] = $request->input($k);
        }
        [$policy, $errors] = PointsService::validate($in);
        if ($errors) {
            throw new ApiException(implode('; ', $errors), 422);
        }
        $stored = [
            'enable' => $policy['enable'] ? '1' : '0',
            'referral_reward' => (string) $policy['referral_reward'],
            'expire_days' => $policy['expire_days'] === null ? '' : (string) $policy['expire_days'],
        ];
        DB::transaction(function () use ($stored) {
            foreach (PointsService::KEYS as $k => $name) {
                $row = ['value' => $stored[$k], 'updated_at' => now()];
                if (DB::table('v2_settings')->where('name', $name)->exists()) {
                    DB::table('v2_settings')->where('name', $name)->update($row);
                } else {
                    DB::table('v2_settings')->insert($row + ['group' => 'manduvpn', 'type' => 'input', 'name' => $name, 'created_at' => now()]);
                }
            }
        });
        Log::info('ManduVPN RC: points policy saved', [
            'admin_id' => (int) optional($request->user())->id,
            'enable' => $stored['enable'],
        ]);
        return $this->success($this->describe($policy, []));
    }

    /** Referral reward history, newest first. Optional filters: inviter_user_id, invitee_user_id, status. */
    public function fetchRewards(Request $request)
    {
        $request->validate([
            'inviter_user_id' => 'nullable|integer|min:1',
            'invitee_user_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:granted,revoked',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $q = DB::table('v2_referral_reward')
            ->when($request->filled('inviter_user_id'), fn ($b) => $b->where('inviter_user_id', (int) $request->input('inviter_user_id')))
            ->when($request->filled('invitee_user_id'), fn ($b) => $b->where('invitee_user_id', (int) $request->input('invitee_user_id')))
            ->when($request->filled('status'), fn ($b) => $b->where('status', $request->input('status')))
            ->orderByDesc('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($current, $pageSize)->get();
        // Emails are looked up separately so this endpoint does not widen the user payload (see the admin
        // user/fetch over-exposure finding) - only id and email are joined in.
        $ids = $rows->pluck('inviter_user_id')->merge($rows->pluck('invitee_user_id'))->unique()->filter()->values();
        $emails = $ids->isEmpty() ? [] : DB::table('v2_user')->whereIn('id', $ids)->pluck('email', 'id')->all();

        return response([
            'data' => $rows->map(fn ($r) => (array) $r + [
                'inviter_email' => $emails[$r->inviter_user_id] ?? null,
                'invitee_email' => $emails[$r->invitee_user_id] ?? null,
            ])->all(),
            'total' => $total,
            'granted_points_total' => (int) DB::table('v2_referral_reward')->where('status', 'granted')->sum('points'),
        ]);
    }

    /** One user's balance and full ledger. */
    public function fetchUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);
        $userId = (int) $request->input('user_id');
        if (!DB::table('v2_user')->where('id', $userId)->exists()) {
            throw new ApiException('user does not exist', 422);
        }
        $limit = (int) $request->input('limit', 100);
        return $this->success([
            'unit' => 'P',
            'user_id' => $userId,
            'balance' => PointsService::balanceOf($userId),
            'expired_total' => PointsService::expiredTotalOf($userId),
            'ledger' => PointsService::ledgerOf($userId, $limit),
            'referral_rewards' => PointsService::rewardsByInviter($userId, $limit),
        ]);
    }

    /** Manual correction: appends a signed ledger row. */
    public function adjust(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'amount' => 'required|integer',
            'expire_days' => 'nullable|integer|min:1|max:3650',
        ]);
        $userId = (int) $request->input('user_id');
        if (!DB::table('v2_user')->where('id', $userId)->exists()) {
            throw new ApiException('user does not exist', 422);
        }
        $amount = (int) $request->input('amount');
        if ($amount === 0 || abs($amount) > PointsService::ADJUST_LIMIT) {
            throw new ApiException('amount must be a non-zero integer within +/-' . PointsService::ADJUST_LIMIT, 422);
        }
        // A negative correction must not drive a balance below zero - points are not a credit line.
        if ($amount < 0 && PointsService::balanceOf($userId) + $amount < 0) {
            throw new ApiException('this correction would make the balance negative', 422);
        }
        $expireDays = $request->filled('expire_days') ? (int) $request->input('expire_days') : null;
        $balance = PointsService::adjust($userId, $amount, $expireDays, (int) optional($request->user())->id);
        return $this->success(['unit' => 'P', 'user_id' => $userId, 'balance' => $balance]);
    }

    /** Revokes a granted referral reward and offsets its points. Idempotent. */
    public function revokeReward(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $done = PointsService::revokeReward((int) $request->input('id'), (int) optional($request->user())->id);
        if (!$done) {
            throw new ApiException('reward does not exist or is already revoked', 422);
        }
        return $this->success(true);
    }

    private function describe(array $policy, array $errors): array
    {
        return [
            'policy' => $policy,
            'unit' => 'P',
            // OD-3: a reward is worth the inviter's current plan's 30-day CNY list price. policy.referral_reward
            // is only the fallback used when that inviter has no plan with a 30-day price - the admin UI must
            // label it that way and must not call it "the reward amount".
            'reward_basis' => PointsService::REWARD_BASIS,
            'reward_basis_periods' => PointsService::REWARD_BASIS_PERIODS,
            'valid' => !$errors,
            'errors' => array_values($errors),
            'rewards_granted' => (int) DB::table('v2_referral_reward')->where('status', 'granted')->count(),
            'rewards_revoked' => (int) DB::table('v2_referral_reward')->where('status', 'revoked')->count(),
            'points_granted_total' => (int) DB::table('v2_points_ledger')->where('type', PointsLedger::TYPE_REFERRAL_REWARD)->sum('amount'),
            // RC scope is accrual + query + admin history. Spending is an Owner decision, not implemented.
            'spending_implemented' => false,
        ];
    }
}
