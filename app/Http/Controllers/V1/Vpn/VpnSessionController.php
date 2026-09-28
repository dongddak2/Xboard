<?php

namespace App\Http\Controllers\V1\Vpn;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\User\Hysteria2ProfileController;
use App\Models\Server;
use App\Models\User;
use App\Models\VpnSession;
use App\Services\BasicServerAssignmentService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ManduVPN stage C: the session the app must hold before it raises a tunnel.
 *
 * POST /api/v1/vpn/sessions   (authenticated)
 *
 * WHY THIS EXISTS. The app calls this before every connect (vpn_connection_manager.dart:117) and refuses to
 * connect if it throws - the session is its only authorisation evidence. The route did not exist, so stage C
 * died here before reaching a node. It fails closed today, which is why it surfaced as a blocker rather than
 * as a silent fake session: the app's fabricated local-session fallback was removed on 2026-09-27.
 *
 * THE RESPONSE SHAPE IS DICTATED BY THE APP, NOT CHOSEN HERE. VpnSession.fromJson (vpn_session.dart:35) is
 * the contract, and four of its properties are load-bearing:
 *  1. `sessionId` is the ONLY non-null cast (`as String`). Omitting it throws a Dart TypeError, and that
 *     throw happens OUTSIDE the app's _guard, so it reaches the UI as a raw type error rather than a handled
 *     failure. It is always present here.
 *  2. `status` falls back to 'ACTIVE' when absent. A refusal sent as 200 would therefore be read as a granted
 *     session and the app would connect. EVERY REFUSAL HERE IS 4xx. This is the single most dangerous failure
 *     this endpoint could have, which is why the regression asserts the status code on every refusal path.
 *  3. `serverId` is parsed with `as String?`, and the app's own Server.id is a String
 *     (server.dart:101 uses json['id'].toString()). An integer here throws. It is cast to string on the way out.
 *  4. `connectionConfig` IS DELIBERATELY ABSENT. The app merges credentialConfig then connectionConfig
 *     (vpn_connection_manager.dart:124) with the session LAST, so any key sent here would silently overwrite
 *     the credential the app already got from the verified Hysteria2 profile endpoint. The session issues
 *     authorisation; it does not carry credentials. Do not add the key "for convenience".
 *
 * ERROR IDENTIFIERS ARE NOT FREELY CHOSEN EITHER. The app maps them through VpnErrorCode.fromServerCode
 * (vpn_error.dart:22), which recognises a fixed vocabulary; anything outside it becomes VpnErrorCode.unknown,
 * i.e. "unknown error" on screen. So this controller reuses the app's own names (SUBSCRIPTION_EXPIRED /
 * SERVER_NOT_ALLOWED / PROTOCOL_NOT_ALLOWED / AUTH_FAILED) instead of inventing VPN_SESSION_* ones. Note the
 * app currently reads that identifier from a `code` key while this envelope puts it in `error`; APP is fixing
 * the client, because `code` is commented out in ApiResponse and every other route already uses `error`.
 *
 * WHAT THIS DOES NOT ENFORCE, ON PURPOSE. Device and concurrent-connection limits are NOT checked here. They
 * are enforced where they already are: the node reports live IPs and UserOnlineService counts them (limiter.go
 * compares deviceLimit to aliveIp, counting IPs). Counting session rows would create a second, divergent
 * definition of "how many devices are connected", and the two disagree under NAT and multi-IP clients. One
 * definition only. This is a decision, not an oversight.
 *
 * CEILING - STATED BECAUSE IT IS REAL. There is no heartbeat and no release route yet, so `last_heartbeat_at`
 * is never updated and NOTHING EXPIRES SESSION ROWS SERVER-SIDE. The app tolerates this: heartbeat() swallows
 * its failure and keeps the tunnel (vpn_session_api.dart:63). Harmless for stage C, but heartbeat and release
 * are required before release, and until they exist a session row is NOT evidence that a tunnel is still up.
 */
class VpnSessionController extends Controller
{
    /** Protocols this stack can actually raise a tunnel for. vless/vmess are wire-valid but unsupported. */
    private const PROTOCOL_TO_NODE_TYPE = [
        'ikev2' => Server::TYPE_IKEV2,
        'hysteria2' => Server::TYPE_HYSTERIA,
    ];

    /** The app's wire values (routing_mode.dart:29). An unknown value is refused, not silently downgraded. */
    private const ROUTING_MODES = ['FULL_TUNNEL', 'SMART_CHINA', 'CUSTOM'];

    public function create(Request $request)
    {
        // serverId arrives as a STRING (the app's Server.id is a String), so it is validated as numeric
        // rather than integer and cast below.
        $request->validate([
            'deviceId' => 'required|string|max:128',
            'serverId' => 'required|numeric|min:1',
            'protocol' => 'required|string|max:16',
            'routingMode' => 'nullable|string|max:16',
        ]);

        // Identity comes from the token and nowhere else: there is no user parameter on this route.
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([403, __('Unauthorized')], null, 'AUTH_FAILED');
        }

        if (!(new UserService())->isAvailable($user)) {
            return $this->fail([403, '이용 중인 구독이 없어 VPN 세션을 발급할 수 없습니다'], null, 'SUBSCRIPTION_EXPIRED');
        }

        $protocol = (string) $request->input('protocol');
        if (!isset(self::PROTOCOL_TO_NODE_TYPE[$protocol])) {
            return $this->fail([403, '지원하지 않는 프로토콜입니다'], null, 'PROTOCOL_NOT_ALLOWED');
        }

        $routingMode = (string) ($request->input('routingMode') ?: 'FULL_TUNNEL');
        if (!in_array($routingMode, self::ROUTING_MODES, true)) {
            return $this->fail([422, '지원하지 않는 라우팅 모드입니다'], null, 'PROTOCOL_NOT_ALLOWED');
        }

        $server = $this->eligibleServerFor($user, (int) $request->input('serverId'), $protocol);
        if (!$server) {
            // Indistinguishable from "no such server": this must not enumerate nodes. 4xx, never 200 - a 200
            // refusal would be read as ACTIVE by the app and it would connect. See the class note.
            return $this->fail([403, '해당 서버에 접속할 권한이 없습니다'], null, 'SERVER_NOT_ALLOWED');
        }

        $now = time();
        $sessionId = (string) Str::uuid();
        $deviceId = (string) $request->input('deviceId');

        $session = new VpnSession();
        $session->forceFill([
            'session_id' => $sessionId,
            'user_id' => (int) $user->id,
            'device_id' => $deviceId,
            'server_id' => (int) $server->id,
            'protocol' => $protocol,
            'routing_mode' => $routingMode,
            'status' => VpnSession::STATUS_ACTIVE,
            'connected_at' => $now,
            'last_heartbeat_at' => null,
        ])->save();

        return response([
            'data' => [
                'sessionId' => $sessionId,
                'deviceId' => $deviceId,
                // String, not int - the app parses this with `as String?`. See the class note.
                'serverId' => (string) $server->id,
                'protocol' => $protocol,
                'routingMode' => $routingMode,
                // ISO-8601: the app reads these with DateTime.tryParse.
                'connectedAt' => date('c', $now),
                'lastHeartbeat' => null,
                'status' => VpnSession::STATUS_ACTIVE,
                // `connectionConfig` is intentionally NOT here. See the class note before adding it.
            ],
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * The requested server, but only if this user may actually connect to it with this protocol. Returns null
     * otherwise - callers must treat null as "refuse", never as "fall back to some other node".
     *
     * Same four predicates the two profile endpoints use, so a session cannot authorise a node whose profile
     * endpoint would then refuse to describe it.
     */
    private function eligibleServerFor(User $user, int $serverId, string $protocol): ?Server
    {
        $query = Server::where('id', $serverId)
            ->where('type', self::PROTOCOL_TO_NODE_TYPE[$protocol])
            ->where('show', true)
            ->whereNull('parent_id')
            // ManduVPN NG-0: group_ids is a JSON integer array.
            ->whereJsonContains('group_ids', (int) $user->group_id);

        // LEGACY tier is entitled to exactly one server; honour that here too.
        if (ServerService::serviceTierOf($user) === BasicServerAssignmentService::TIER) {
            if ($user->assigned_server_id === null) {
                return null;
            }
            $query->where('id', (int) $user->assigned_server_id);
        }

        $server = $query->first();
        if (!$server) {
            return null;
        }

        // A Hysteria 1 node cannot serve this app: General::buildHysteria emits nothing for it and the
        // Hysteria2 profile endpoint refuses it with 404. Issuing a session for it would hand the app an
        // authorisation it cannot use. The constant is read from the profile controller ON PURPOSE - these two
        // must agree about which version is serviceable, and sharing the number is what keeps them agreeing.
        if ($protocol === 'hysteria2'
            && data_get($server->protocol_settings, 'version') !== Hysteria2ProfileController::SUPPORTED_VERSION) {
            return null;
        }

        return $server;
    }
}
