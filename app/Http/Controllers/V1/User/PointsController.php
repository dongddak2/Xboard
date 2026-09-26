<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\PointsService;
use Illuminate\Http\Request;

/**
 * ManduVPN RC: the signed-in user's referral POINTS (RC_CONTRACTS.md §4).
 * GET /api/v1/user/points -> balance, expired total, ledger, earned referral rewards, and the current policy.
 *
 * Read-only. `unit` is always "P": points are not currency and the frontend must never render them as KRW or
 * CNY. Spending points is out of RC scope, so there is no write endpoint here.
 * Nothing in this response comes from v2_user.balance or v2_user.commission_balance.
 */
class PointsController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
        ]);
        $userId = (int) $request->user()->id;
        $limit = (int) $request->input('limit', 100);
        [$policy] = PointsService::policy();

        return $this->success([
            'unit' => 'P',
            'balance' => PointsService::balanceOf($userId),
            'expired_total' => PointsService::expiredTotalOf($userId),
            'ledger' => PointsService::ledgerOf($userId, $limit),
            'referral_rewards' => PointsService::rewardsByInviter($userId, $limit),
            // So the referral screen can say "invite a friend, earn N P" without hardcoding the amount.
            // OD-3: the amount depends on the plan THIS user is on, so referral_reward is resolved per user
            // instead of being read straight out of the policy - the policy value is only the fallback.
            'policy' => [
                'enabled' => (bool) $policy['enable'],
                'referral_reward' => PointsService::referralPointsFor($request->user(), $policy),
                'reward_basis' => PointsService::REWARD_BASIS,
                'referral_reward_fallback' => $policy['referral_reward'],
                'expire_days' => $policy['expire_days'],
            ],
        ]);
    }
}
