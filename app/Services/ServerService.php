<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers()
    {
        return Server::orderBy('sort', 'ASC')
            ->get()
            ->transform(function (Server $server) {
                $server->loadServerStatus();
                return $server;
            });
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        // ManduVPN PP-C1: a BASIC-tier user sees ONLY the assigned server; BASIC without an assignment sees
        // nothing (fail closed, never every server). No plan / missing plan / tier NULL / pro / master / vip keep
        // the plain group behaviour. Same rule as the node credential list (getAvailableUsers with a node id).
        $basic = self::serviceTierOf($user) === BasicServerAssignmentService::TIER; // ManduVPN PLAN-ARCH-1A: one source of truth (LEGACY)
        if ($basic && $user->assigned_server_id === null) {
            return [];
        }
        return Server::whereJsonContains('group_ids', (int) $user->group_id) // ManduVPN NG-0: group_ids is a JSON integer array
            ->where('show', true)
            ->when($basic, fn ($query) => $query->where('id', (int) $user->assigned_server_id))
            ->orderBy('sort', 'ASC')
            ->get()
            ->transform(function (Server $server) use ($user) {
                $server->loadParentCreatedAt();
                $server->handlePortAllocation();
                $server->loadServerStatus();
                if ($server->type === 'shadowsocks') {
                    $server->server_key = Helper::getServerKey($server->created_at, 16);
                }
                $server->generateShadowsocksPassword($user);
                $server->generateIkev2Credentials($user);

                return $server;
            })
            ->toArray();
    }

    /** 
     * 加
     */

    /**
     * 根据权限组获取可用的用户列表
     * @param array $groupIds
     * @return Collection
     */
    public static function getAvailableUsers(array $groupIds, ?int $nodeId = null, ?string $nodeType = null)
    {
        $users = User::toBase()
            ->whereIn('group_id', $groupIds)
            ->where(fn ($q) => MasterAccess::includeUnlimitedTraffic($q))
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            // ManduVPN PP-C1: when the asking node is known, a BASIC-tier user (current plan's service_tier) is
            // delivered ONLY to the node equal to assigned_server_id (NULL -> no node: fail closed). No plan, a
            // missing plan, tier NULL and pro/master/vip keep the plain group behaviour. One query with a
            // subquery - no per-user plan lookup. $nodeId NULL (callers without a node id) = unchanged.
            ->when($nodeId !== null, function ($query) use ($nodeId) {
                $query->where(function ($q) use ($nodeId) {
                    $q->whereNull('plan_id')
                        ->orWhereNotIn('plan_id', function ($sub) {
                            $sub->select('id')->from('v2_plan')->where('service_tier', BasicServerAssignmentService::TIER); // ManduVPN PLAN-ARCH-1A
                        })
                        ->orWhere('assigned_server_id', $nodeId);
                });
            })
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();

        if ($users->isNotEmpty()) {
            $masterIds = array_fill_keys(MasterAccess::userIds($users->pluck('id')->all()), true);
            $users = $users->map(function ($user) use ($masterIds) {
                if (isset($masterIds[(int)$user->id])) $user->device_limit = null;
                return $user;
            });
        }

        // ManduVPN SERVER-QUOTA-1: a user over THIS node's per-user cap is dropped from THIS node's list,
        // and from nothing else - their other entitled nodes and every other user are untouched. The cap is
        // enforced here, on the node's own credential list, because that is the path a node actually reads;
        // a dashboard-only limit would not be a limit. Enforcement is therefore bounded by the node's report
        // and fetch interval, and an already-established session is not torn down from here.
        // Only when the asking node is known: callers that pass no node id (the device-limit count in
        // UniProxyController@alivelist, the Tidalab controllers) stay byte-identical, exactly as the PP-C1
        // and HY2-CRED-1 blocks above and below require.
        // An unmeasurable figure restricts NOBODY - see the fail-open ruling in ServerQuotaService::state().
        if ($nodeId !== null && $users->isNotEmpty()) {
            $overIds = ServerQuotaService::overLimitUserIds(
                $users->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $nodeId,
                (string) $nodeType
            );
            if ($overIds !== []) {
                $overIds = array_flip($overIds);
                $users = $users->reject(fn ($user) => isset($overIds[(int) $user->id]))->values();
            }
        }

        // ManduVPN HY2-CRED-1: a hysteria node authenticates the SHORT-LIVED credential, not v2_user.uuid. The
        // swap happens here, on the node's own list, because that is where expiry is actually enforced: a user
        // whose credential has lapsed is absent from the list, so the node has no value to accept. Callers that
        // pass no node type (the Tidalab controllers, the IKEv2 node API, the device-limit count in
        // UniProxyController@alivelist) are byte-identical to before - the device-limit count in particular must
        // NOT be filtered by credential presence, or a user without a live credential would stop being counted.
        if ($nodeType !== null && Server::normalizeType($nodeType) === Server::TYPE_HYSTERIA) {
            return Hy2CredentialService::applyToNodeUsers($users);
        }
        return $users;
    }

    // ManduVPN PP-C1: service tier of the user's CURRENT plan (explicit plan.service_tier only - never names,
    // groups or servers). NULL = no plan / missing plan / legacy plan without a tier = plain group behaviour.
    public static function serviceTierOf(User $user): ?string
    {
        return $user->plan_id ? \App\Models\Plan::whereKey($user->plan_id)->value('service_tier') : null;
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        // TODO: remove on 1.8.0
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array))
                $routes[$k]['match'] = $array;
        }
        // TODO: remove on 1.8.0
        return $routes;
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, $serverType)
    {
        return Server::query()
            ->where('type', Server::normalizeType($serverType))
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}
