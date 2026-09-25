<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;

/**
 * ManduVPN PP-C2: the single authoritative decision for v2_user.assigned_server_id (BASIC single-server entitlement).
 *
 * Tier comes ONLY from plan.service_tier (never plan / group / server names).
 * Eligible BASIC server: type ikev2, show = true, parent_id IS NULL (the node itself pulls credentials),
 * group_ids contains the BASIC plan's group_id (JSON integer, same form as ServerService::getAvailableServers; NG-0).
 * Choice: the eligible server with the fewest ACTIVE BASIC users (BASIC-tier plan, not banned, not expired);
 * random only among the servers tied for that minimum. BASIC -> BASIC keeps a still-eligible server.
 * Not BASIC -> NULL. BASIC with no eligible server -> ApiException 422 (fail closed; callers write nothing).
 * Callers persist the result in the SAME write/transaction as the plan change.
 * Concurrency: two simultaneous assignments may pick the same least-loaded server (balance is best effort);
 * eligibility and fail-closed behaviour do not depend on the race.
 */
class BasicServerAssignmentService
{
    public const TIER = 'legacy'; // ManduVPN PLAN-ARCH-1A: single-server tier = LEGACY (기존 요금제); basic/pro/master = plain group access

    public static function eligibleServerIds(int $groupId): array
    {
        return Server::where('type', 'ikev2')
            ->where('show', true)
            ->whereNull('parent_id')
            ->whereJsonContains('group_ids', (int) $groupId) // ManduVPN NG-0: group_ids is a JSON integer array
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** [server_id => active BASIC users assigned to it] for the given servers (0 when none). One query. */
    public static function activeBasicCounts(array $serverIds): array
    {
        $now = time();
        $rows = User::toBase()
            ->whereIn('assigned_server_id', $serverIds)
            ->whereIn('plan_id', function ($q) {
                $q->select('id')->from('v2_plan')->where('service_tier', self::TIER);
            })
            ->where('banned', 0)
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>=', $now);
            })
            ->groupBy('assigned_server_id')
            ->selectRaw('assigned_server_id, count(*) as c')
            ->pluck('c', 'assigned_server_id');
        $out = [];
        foreach ($serverIds as $id) {
            $out[$id] = (int) ($rows[$id] ?? 0);
        }
        return $out;
    }

    /** assigned_server_id the user must hold under $plan (null = no single-server restriction). */
    public static function resolve(?Plan $plan, ?int $currentAssigned, ?string $currentTier): ?int
    {
        if (!$plan || $plan->service_tier !== self::TIER) {
            return null;
        }
        $eligible = self::eligibleServerIds((int) $plan->group_id);
        if (!$eligible) {
            throw new ApiException('기존 요금제(LEGACY) 플랜에 배정 가능한 서버가 없습니다', 422); // ManduVPN PLAN-ARCH-1A
        }
        if ($currentTier === self::TIER && $currentAssigned !== null && in_array($currentAssigned, $eligible, true)) {
            return $currentAssigned;
        }
        $counts = self::activeBasicCounts($eligible);
        $min = min($counts);
        $tied = array_keys(array_filter($counts, fn ($c) => $c === $min));
        return $tied[random_int(0, count($tied) - 1)];
    }

    /** For a loaded User whose plan attributes were just changed in memory (OrderService::open). */
    public static function applyTo(User $user): void
    {
        $prevPlanId = $user->getOriginal('plan_id');
        $prevAssigned = $user->getOriginal('assigned_server_id');
        $user->assigned_server_id = self::resolve(
            $user->plan_id ? Plan::find($user->plan_id) : null,
            $prevAssigned !== null ? (int) $prevAssigned : null,
            $prevPlanId ? Plan::whereKey($prevPlanId)->value('service_tier') : null
        );
    }
}
