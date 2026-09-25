<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\BasicServerAssignmentService as BSA;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN PP-C3: admin view and control of the BASIC single-server assignment.
 * Every rule comes from the PP-C2 BasicServerAssignmentService (eligibility, active-user counts, least-loaded
 * choice); this class only reports it and writes an admin-chosen / freshly-chosen server. Tier comes ONLY from the
 * user's current plan.service_tier. A change is written with a conditional update (same plan, same previous
 * assignment), so a concurrent plan change or reassignment is never overwritten (409).
 */
class BasicServerAdminService
{
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const VALID = 'VALID';
    public const MISSING = 'MISSING';
    public const INVALID = 'INVALID';

    public static function describe(User $user): array
    {
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $assigned = $user->assigned_server_id !== null ? (int) $user->assigned_server_id : null;
        $server = $assigned !== null ? Server::find($assigned) : null;
        $out = [
            'user_id' => (int) $user->id,
            'plan_id' => $plan ? (int) $plan->id : null,
            'service_tier' => $plan ? $plan->service_tier : null,
            'assigned_server_id' => $assigned,
            'assigned_server' => $server ? ['id' => (int) $server->id, 'name' => $server->name, 'type' => $server->type, 'host' => $server->host,
                'show' => (bool) $server->show, 'parent_id' => $server->parent_id !== null ? (int) $server->parent_id : null] : null,
            'state' => self::NOT_APPLICABLE,
            'eligible_servers' => [],
        ];
        if (!$plan || $plan->service_tier !== BSA::TIER) {
            return $out;
        }
        $eligible = BSA::eligibleServerIds((int) $plan->group_id);
        $out['state'] = $assigned === null ? self::MISSING : (in_array($assigned, $eligible, true) ? self::VALID : self::INVALID);
        $counts = $eligible ? BSA::activeBasicCounts($eligible) : [];
        $out['eligible_servers'] = Server::whereIn('id', $eligible)->orderBy('id')->get(['id', 'name', 'host'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'host' => $s->host, 'active_basic_users' => $counts[(int) $s->id] ?? 0])
            ->values()->all();
        return $out;
    }

    /** Admin picks a server: it must satisfy the exact PP-C2 eligibility for the user's current BASIC plan. */
    public static function assign(User $user, int $serverId): void
    {
        $plan = self::basicPlanOf($user);
        if (!in_array($serverId, BSA::eligibleServerIds((int) $plan->group_id), true)) {
            throw new ApiException('배정할 수 없는 서버입니다 (IKEv2 · 노출 · 상위 서버 · 플랜 그룹 포함 조건)', 422);
        }
        self::write($user, $plan, $serverId, 'manual');
    }

    /** Explicit admin request for a NEW load-based choice (unlike renewal, a valid current server is not kept). */
    public static function reassign(User $user): void
    {
        $plan = self::basicPlanOf($user);
        self::write($user, $plan, BSA::resolve($plan, null, null), 'auto');
    }

    private static function basicPlanOf(User $user): Plan
    {
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        if (!$plan || $plan->service_tier !== BSA::TIER) {
            throw new ApiException('단일 서버 배정 대상이 아닙니다 (BASIC 등급 플랜 사용자만 가능)', 422);
        }
        return $plan;
    }

    private static function write(User $user, Plan $plan, int $serverId, string $how): void
    {
        $old = $user->assigned_server_id !== null ? (int) $user->assigned_server_id : null;
        $n = User::where('id', $user->id)->where('plan_id', $plan->id)
            ->where(function ($q) use ($old) {
                $old === null ? $q->whereNull('assigned_server_id') : $q->where('assigned_server_id', $old);
            })
            ->update(['assigned_server_id' => $serverId]);
        if ($n !== 1) {
            throw new ApiException('회원 정보가 동시에 변경되었습니다. 새로고침 후 다시 시도하세요', 409);
        }
        Log::info('ManduVPN BASIC server changed by admin', ['user_id' => $user->id, 'from' => $old, 'to' => $serverId, 'how' => $how]);
    }
}
