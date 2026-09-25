<?php

namespace App\Http\Middleware;

use App\Models\Server;
use App\Services\IkeV2NodeService;
use Closure;
use Illuminate\Http\Request;

/**
 * ManduVPN PP-C4-1C: authentication for the IKEv2 node API.
 * Headers only: "Authorization: Bearer mvn_<64 hex>" + "X-Node-Id: <v2_server.id>". A token in the query string is
 * refused (never accepted). Order: HTTPS (production) -> no token in URL -> X-Node-Id format -> Bearer format ->
 * server exists + type ikev2 + root (parent_id NULL) -> IkeV2NodeService::verifyToken (current, or previous inside
 * its overlap window; constant-time). Every auth failure after the format checks is the same 401 UNAUTHORIZED.
 * show is NOT checked here (ping/report stay usable for a disabled node; /users refuses it).
 * Nothing here logs the request or its headers. A successful authentication counts as contact (last_seen_at).
 */
class IkeV2NodeAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('production') && !$request->isSecure()) {
            return self::deny(403, 'HTTPS_REQUIRED');
        }
        if ($request->query->has('token') || $request->query->has('access_token')) {
            return self::deny(400, 'TOKEN_IN_URL');
        }
        $id = $request->header('X-Node-Id');
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
            return self::deny(400, 'NODE_ID_INVALID');
        }
        $auth = (string) $request->header('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if (!preg_match(IkeV2NodeService::TOKEN_PATTERN, $token)) {
            return self::deny(401, 'UNAUTHORIZED');
        }
        $server = Server::find((int) $id);
        if (!$server || $server->type !== 'ikev2' || $server->parent_id || !IkeV2NodeService::verifyToken((int) $server->id, $token)) {
            return self::deny(401, 'UNAUTHORIZED');
        }
        IkeV2NodeService::recordHeartbeat((int) $server->id, []);
        $request->attributes->set('ikev2_node', $server);
        return $next($request);
    }

    public static function deny(int $status, string $code)
    {
        return response()->json(['schema' => 'manduvpn.ikev2.error.v1', 'error' => $code], $status)->header('Cache-Control', 'no-store');
    }
}
