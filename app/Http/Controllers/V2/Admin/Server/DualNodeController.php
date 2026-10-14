<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DualNodeController extends Controller
{
    public function save(Request $request)
    {
        $input = $request->validate([
            'request_id' => 'required|uuid',
            'name' => 'required|string|max:80',
            'host' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'group_ids' => 'required|array|min:1|max:20',
            'group_ids.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'rate' => 'required|numeric|min:0|max:1000',
            'cyber_port' => 'required|integer|between:1,65535|not_in:500,4500',
            'obfs_password' => 'required|string|min:4|max:128',
        ]);
        $key = 'mandu-dual-create:' . $input['request_id'];
        $fingerprint = hash('sha256', json_encode($input));
        $lock = Cache::lock($key . ':lock', 30);
        if (!$lock->get()) return response()->json(['message' => '저장 중입니다. 잠시 후 다시 확인해주세요.'], 409);
        try {
            $previous = Cache::get($key);
            if ($previous) {
                if ($previous['fingerprint'] !== $fingerprint) return response()->json(['message' => '이 저장 요청은 이미 사용됐습니다. 창을 다시 열어주세요.'], 409);
                if (Server::whereIn('id', [$previous['result']['ikev2_id'], $previous['result']['cyber_id']])->count() !== 2) return response()->json(['message' => '생성했던 노드가 변경됐습니다. 목록을 확인해주세요.'], 409);
                return $this->success($previous['result']);
            }
            $result = DB::transaction(function () use ($input, $key, $fingerprint) {
                $common = ['host' => strtolower($input['host']), 'group_ids' => array_map('intval', $input['group_ids']), 'rate' => $input['rate'], 'show' => 1, 'tags' => [], 'route_ids' => []];
                $ike = Server::create(array_merge($common, ['type' => 'ikev2', 'name' => $input['name'] . ' IKEv2', 'port' => 500, 'server_port' => 500, 'protocol_settings' => ['vpn_pool' => '10.11.12.0/24', 'dns' => ['1.1.1.1', '1.0.0.1'], 'mtu' => 1400]]));
                $cyber = Server::create(array_merge($common, ['type' => 'hysteria', 'name' => $input['name'] . ' Cyber', 'port' => $input['cyber_port'], 'server_port' => $input['cyber_port'], 'protocol_settings' => ['version' => 2, 'alpn' => 'h3', 'tls' => ['server_name' => strtolower($input['host']), 'allow_insecure' => false], 'bandwidth' => ['up' => 0, 'down' => 0], 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => $input['obfs_password']]] ]));
                $result = ['ikev2_id' => $ike->id, 'cyber_id' => $cyber->id, 'host' => strtolower($input['host']), 'cyber_port' => $input['cyber_port']];
                // Cache contains IDs only, never credentials. The lock prevents double-click duplicates.
                if (!Cache::put($key, ['fingerprint' => $fingerprint, 'result' => $result], 3600)) { throw new \RuntimeException('Node save receipt unavailable'); }
                return $result;
            });
            return $this->success($result);
        } catch (\Throwable $error) {
            Cache::forget($key);
            throw $error;
        } finally { $lock->release(); }
    }
}
