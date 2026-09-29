<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;
use Illuminate\Http\JsonResponse;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly UserOnlineService $userOnlineService
    ) {
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        $node = $request->input('node_info');
        $nodeType = $node->type;
        $nodeId = $node->id;
        Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        // ManduVPN HY2-CRED-1: the node type is passed so a hysteria node is handed the short-lived credential
        // instead of v2_user.uuid. Every other node type is unaffected.
        $users = ServerService::getAvailableUsers($node->group_ids, (int) $node->id, (string) $nodeType); // ManduVPN PP-C1: node-aware (BASIC single server)

        $response['users'] = $users;

        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false) {
            return response(null, 304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }
        $data = array_filter($res, function ($item) {
            return is_array($item)
                && count($item) === 2
                && is_numeric($item[0])
                && is_numeric($item[1]);
        });
        if (empty($data)) {
            return $this->success(true);
        }
        $node = $request->input('node_info');
        $nodeType = $node->type;
        $nodeId = $node->id;

        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId),
            count($data),
            3600
        );
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId),
            time(),
            3600
        );

        $userService = new UserService();
        $userService->trafficFetch($node->toArray(), $nodeType, $data);
        return $this->success(true);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $node = $request->input('node_info');
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;

        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'obfs' => $protocolSettings['obfs'],
                'obfs_settings' => $protocolSettings['obfs_settings'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null
                    }
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls']
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => $protocolSettings['server_name'],
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'tls_settings' =>
                        match ((int) $protocolSettings['tls']) {
                            2 => $protocolSettings['reality_settings'],
                            default => $protocolSettings['tls_settings']
                        }
            ],
            'hysteria' => [
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null
                        ],
                        default => []
                    }
            ],
            'tuic' => [
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => "3s",
            ],
            'socks' => [
                'server_port' => (int) $serverPort,
            ],
            'ikev2' => [
                // IKEv2 Agent가 ipsec.conf의 rightsourceip/rightdns/MTU를
                // 구성하는 데만 사용. 실제 EAP 계정 목록은 /UniProxy/user에서 받는다.
                'host' => $host,
                'vpn_pool' => $protocolSettings['vpn_pool'],
                'dns' => $protocolSettings['dns'],
                'mtu' => (int) $protocolSettings['mtu'],
            ],
            default => []
        };

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60)
        ];

        if (!empty($node['route_ids'])) {
            $response['routes'] = ServerService::getRoutes($node['route_ids']);
        }

        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match', '') ?? '', $eTag) !== false) {
            return response(null, 304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    // 获取在线用户数据（wyx2685
    public function alivelist(Request $request): JsonResponse
    {
        $node = $request->input('node_info');
        $deviceLimitUsers = ServerService::getAvailableUsers($node->group_ids, (int) $node->id) // ManduVPN PP-C1: same entitlement as user()
            ->where('device_limit', '>', 0);
        $alive = $this->userOnlineService->getAliveList($deviceLimitUsers);
        return response()->json(['alive' => (object) $alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request): JsonResponse
    {
        $node = $request->input('node_info');
        $data = json_decode(request()->getContent(), true);
        if ($data === null) {
            return response()->json([
                'error' => 'Invalid online data'
            ], 400);
        }
        $this->userOnlineService->updateAliveData($data, $node->type, $node->id);
        return response()->json(['data' => true]);
    }
}
