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
 * THE OTHER TWO ROUTES LIVE HERE TOO (added 2026-09-29, BUILD-FIRST):
 *   POST   /api/v1/vpn/sessions/{sessionId}/heartbeat   keeps a session alive
 *   DELETE /api/v1/vpn/sessions/{sessionId}             releases one
 * They are what makes the stored status mean anything: until they existed `last_heartbeat_at` was never
 * written and no row ever left ACTIVE, so ACTIVE only ever meant "was issued once".
 *
 * ★ HEARTBEAT INVERTS THE 4xx RULE, AND THAT IS MEASURED, NOT PREFERRED. Everywhere else here a refusal is
 * 4xx, because the app reads a 200 without a status as ACTIVE. On heartbeat the app does the opposite:
 * VpnSessionApi.heartbeat CATCHES VpnException AND RETURNS null (vpn_session_api.dart:80-86), and
 * _startHeartbeat reads null as "no news this cycle" and KEEPS THE TUNNEL UP
 * (vpn_connection_manager.dart:219-223). So a 403 on heartbeat is swallowed and the tunnel survives, while a
 * 200 carrying status REVOKED makes the app call disconnect() at once. For this one route 200 + REVOKED is the
 * fail-closed answer and 4xx is the fail-open one. Every heartbeat refusal below is therefore 200 + REVOKED,
 * including "no such session" and "not your session", which answer identically so a session id cannot be
 * probed. Do not "fix" this to 403 without re-reading those two app files.
 *
 * TTL CLEANUP IS A LAZY SWEEP, NOT A SCHEDULER. Both new routes first expire this caller's own stale ACTIVE
 * rows (see expireStaleSessions). No cron entry, no queue worker, nothing to deploy or monitor.
 * CEILING, STATED PLAINLY: a user who never calls either route again keeps a stale ACTIVE row forever. That
 * costs nothing today because session rows are not an enforcement point (see the paragraph above). The day a
 * limit is counted from this table, the sweep has to become a scheduled job - that is the trigger.
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

    /**
     * How long a session survives without a heartbeat. The app heartbeats every 60 seconds
     * (vpn_connection_manager.dart:216), so this tolerates four consecutive misses before the row is expired -
     * a phone that loses signal in a lift does not lose its session. Shortening it below ~120 starts expiring
     * live tunnels; there is no reason to lengthen it, because nothing is enforced from these rows.
     */
    public const HEARTBEAT_TTL_SECONDS = 300;

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

        return $this->sessionResponse($session);
    }

    /**
     * POST /api/v1/vpn/sessions/{sessionId}/heartbeat - keep this session alive, or tell the app to drop it.
     *
     * ALWAYS 200. See the HEARTBEAT INVERTS THE 4xx RULE paragraph in the class note before changing that: a
     * 4xx here is swallowed by the app and the tunnel stays up, so a refusal must arrive as
     * 200 + status REVOKED, which is what makes the app disconnect.
     */
    public function heartbeat(Request $request, string $sessionId)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            // The one exception to "always 200": with no identity there is no session to answer about, and the
            // `user` middleware has already refused most of these with 403 before we get here.
            return $this->fail([403, __('Unauthorized')], null, 'AUTH_FAILED');
        }

        $this->expireStaleSessions($user);

        // user_id in the WHERE clause, not checked afterwards: another account's session is simply not found,
        // and "not found" and "not yours" get the identical answer below.
        $session = VpnSession::where('session_id', $sessionId)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$session || $session->status !== VpnSession::STATUS_ACTIVE) {
            return $this->revokedResponse($sessionId);
        }

        // Entitlement is re-checked on every beat, which is the only thing that makes a mid-session
        // cancellation take effect: nothing else would ever look at this row again.
        if (!(new UserService())->isAvailable($user)) {
            $session->forceFill(['status' => VpnSession::STATUS_REVOKED])->save();
            return $this->revokedResponse($sessionId);
        }

        $session->forceFill(['last_heartbeat_at' => time()])->save();

        return $this->sessionResponse($session);
    }

    /**
     * DELETE /api/v1/vpn/sessions/{sessionId} - the client says it has taken the tunnel down.
     *
     * 204 with no body: the app ignores the body entirely and only looks at the status code
     * (api_client.dart:129-136). Idempotent - releasing an already-released session is a 204, because it is
     * true. An unknown or foreign session id is 404, which the app swallows; the value of answering 404 rather
     * than 204 is that the regression can prove one account cannot end another account's session.
     */
    public function destroy(Request $request, string $sessionId)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([403, __('Unauthorized')], null, 'AUTH_FAILED');
        }

        $this->expireStaleSessions($user);

        $session = VpnSession::where('session_id', $sessionId)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$session) {
            // Indistinguishable from "not yours", same as everywhere else here.
            return $this->fail([404, '세션을 찾을 수 없습니다'], null, 'SESSION_NOT_FOUND');
        }

        if ($session->status === VpnSession::STATUS_ACTIVE) {
            $session->forceFill(['status' => VpnSession::STATUS_ENDED])->save();
        }

        return response()->noContent();
    }

    /**
     * Expire this user's own ACTIVE rows that have gone quiet past the TTL.
     *
     * ONE UPDATE, THIS USER ONLY. Sweeping the whole table on every request would make one user's heartbeat do
     * work proportional to everyone else's sessions; the (user_id, status) index makes this one narrow.
     * COALESCE, not two branches: a row that has never been beaten is measured from `connected_at`, which is
     * exactly the case that matters (issued, then the app died before its first beat). COALESCE is plain SQL on
     * both SQLite and MySQL, so unlike the group_ids match this behaves the same on either engine.
     */
    private function expireStaleSessions(User $user): void
    {
        VpnSession::where('user_id', (int) $user->id)
            ->where('status', VpnSession::STATUS_ACTIVE)
            ->whereRaw('COALESCE(last_heartbeat_at, connected_at) < ?', [time() - self::HEARTBEAT_TTL_SECONDS])
            ->update(['status' => VpnSession::STATUS_EXPIRED]);
    }

    /**
     * The one response shape both 200 paths use, because the app parses both with VpnSession.fromJson. Keeping
     * one builder is the only way create and heartbeat cannot drift apart on the four load-bearing keys.
     */
    private function sessionResponse(VpnSession $session)
    {
        return $this->noStore([
            'sessionId' => (string) $session->session_id,
            'deviceId' => (string) $session->device_id,
            // String, not int - the app parses this with `as String?`. See the class note.
            'serverId' => (string) $session->server_id,
            'protocol' => (string) $session->protocol,
            'routingMode' => (string) $session->routing_mode,
            // ISO-8601: the app reads these with DateTime.tryParse.
            'connectedAt' => date('c', (int) $session->connected_at),
            'lastHeartbeat' => $session->last_heartbeat_at === null
                ? null
                : date('c', (int) $session->last_heartbeat_at),
            'status' => $this->wireStatus((string) $session->status),
            // `connectionConfig` is intentionally NOT here. See the class note before adding it.
        ]);
    }

    /**
     * The heartbeat refusal. Carries `sessionId` because it is the app's only non-null cast - omitting it would
     * raise a Dart TypeError instead of a clean disconnect - and carries nothing else: every other field has a
     * default in the app and none of them is read once `isRevoked` is true. The id is echoed from the request,
     * so an unknown id and a foreign id produce byte-identical answers.
     */
    private function revokedResponse(string $sessionId)
    {
        return $this->noStore([
            'sessionId' => $sessionId,
            'status' => VpnSession::STATUS_REVOKED,
        ]);
    }

    /** ACTIVE stays ACTIVE; every other stored reason is REVOKED, the only other value the app knows. */
    private function wireStatus(string $storedStatus): string
    {
        return $storedStatus === VpnSession::STATUS_ACTIVE
            ? VpnSession::STATUS_ACTIVE
            : VpnSession::STATUS_REVOKED;
    }

    /** A session response is never cacheable: it is per-user authorisation state. */
    private function noStore(array $data)
    {
        return response(['data' => $data])
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
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
