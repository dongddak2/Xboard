<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\IkeV2Node;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN PP-C4-1B: IKEv2 node identity, per-node token lifecycle and health storage (panel side only).
 *
 * Eligible node: v2_server row with type 'ikev2' and parent_id NULL. A child node has no identity of its own
 * (Server::loadServerStatus keeps status under the parent id; PP-C2 assigns root nodes only), so it gets no token.
 * show is NOT checked: it stays the admin enable/disable switch and surfaces as the DISABLED state.
 *
 * Token: "mvn_" + bin2hex(random_bytes(32)) (256 bits). Only sha256(token) is stored (unsalted is sufficient for a
 * 256-bit random secret); the plaintext is returned once by issue()/rotate(), never logged, never retrievable.
 * Rotation: current hash -> previous hash (valid ROTATION_OVERLAP seconds), new hash -> current. One previous slot.
 * Revocation: clears current + previous immediately; health history is kept; issue() may be called again.
 *
 * State (derived, never stored; first match wins):
 *   DISABLED      server.show = 0
 *   UNREGISTERED  no metadata row or no active token (never issued / revoked)
 *   REGISTERED    token active, node never seen (first_seen_at NULL)
 *   STALE         last_seen_at older than HEARTBEAT_TTL
 *   BOOTSTRAPPING seen recently, but no user list applied yet (applied_revision NULL)
 *   HEALTHY       seen recently and applied_revision set
 * HEARTBEAT_TTL = 300 s, the window xboard already uses for is_online (Server::loadServerStatus).
 * PP-C2 does not read any of this yet.
 */
class IkeV2NodeService
{
    public const TOKEN_PREFIX = 'mvn_';
    public const TOKEN_PATTERN = '/^mvn_[0-9a-f]{64}$/';
    public const ROTATION_OVERLAP = 86400;
    public const HEARTBEAT_TTL = 300;
    private const HEALTH_FIELDS = ['applied_revision' => 64, 'agent_version' => 64, 'strongswan_version' => 64, 'last_error' => 255];

    /** Root IKEv2 server or 422 (same status as the PP-C3 admin assignment rules). */
    public static function eligibleServer(int $serverId): Server
    {
        $server = Server::find($serverId);
        if (!$server) {
            throw new ApiException('서버가 존재하지 않습니다', 422);
        }
        if ($server->type !== 'ikev2' || $server->parent_id) {
            throw new ApiException('IKEv2 상위(root) 서버만 노드 토큰을 받을 수 있습니다', 422);
        }
        return $server;
    }

    /** First token for a node without an active token (409 otherwise). Returns the plaintext exactly once. */
    public static function issue(int $serverId): string
    {
        self::eligibleServer($serverId);
        $now = time();
        DB::table('v2_ikev2_node')->insertOrIgnore(['server_id' => $serverId, 'created_at' => $now, 'updated_at' => $now]);
        $token = self::newToken();
        $ok = DB::table('v2_ikev2_node')->where('server_id', $serverId)->whereNull('token_hash')
            ->update(['token_hash' => self::hash($token), 'token_prev_hash' => null, 'token_prev_expires_at' => null, 'updated_at' => $now]);
        if (!$ok) {
            throw new ApiException('이미 활성 토큰이 있습니다. 교체(rotate)를 사용하세요', 409);
        }
        Log::info('ManduVPN IKEv2 node token issued', ['server_id' => $serverId]);
        return $token;
    }

    /** New current token; the old one stays valid for ROTATION_OVERLAP seconds. Returns the plaintext exactly once. */
    public static function rotate(int $serverId): string
    {
        self::eligibleServer($serverId);
        $current = DB::table('v2_ikev2_node')->where('server_id', $serverId)->value('token_hash');
        if (!$current) {
            throw new ApiException('활성 토큰이 없습니다. 먼저 발급(issue)하세요', 409);
        }
        $token = self::newToken();
        $now = time();
        $ok = DB::table('v2_ikev2_node')->where('server_id', $serverId)->where('token_hash', $current)->update([
            'token_hash' => self::hash($token), 'token_prev_hash' => $current, 'token_prev_expires_at' => $now + self::ROTATION_OVERLAP, 'updated_at' => $now,
        ]);
        if (!$ok) {
            throw new ApiException('토큰이 동시에 변경되었습니다. 다시 시도하세요', 409);
        }
        Log::info('ManduVPN IKEv2 node token rotated', ['server_id' => $serverId]);
        return $token;
    }

    /** Invalidates current AND previous token immediately. Works for any server id (even if no longer eligible). */
    public static function revoke(int $serverId): bool
    {
        $n = DB::table('v2_ikev2_node')->where('server_id', $serverId)
            ->where(fn ($q) => $q->whereNotNull('token_hash')->orWhereNotNull('token_prev_hash'))
            ->update(['token_hash' => null, 'token_prev_hash' => null, 'token_prev_expires_at' => null, 'updated_at' => time()]);
        if ($n) {
            Log::info('ManduVPN IKEv2 node token revoked', ['server_id' => $serverId]);
        }
        return $n > 0;
    }

    /** Internal check for C4-1C: current token, or previous token inside its overlap window. Constant-time compare. */
    public static function verifyToken(int $serverId, string $token): bool
    {
        $row = DB::table('v2_ikev2_node')->where('server_id', $serverId)->first(['token_hash', 'token_prev_hash', 'token_prev_expires_at']);
        if (!$row || !$row->token_hash || $token === '') {
            return false;
        }
        $h = self::hash($token);
        if (hash_equals($row->token_hash, $h)) {
            return true;
        }
        return $row->token_prev_hash !== null && (int) $row->token_prev_expires_at > time() && hash_equals($row->token_prev_hash, $h);
    }

    /** Health storage for later heartbeats: active-token nodes only; unknown keys ignored; values truncated. */
    public static function recordHeartbeat(int $serverId, array $info): bool
    {
        $now = time();
        $set = ['last_seen_at' => $now, 'updated_at' => $now, 'first_seen_at' => DB::raw('COALESCE(first_seen_at, ' . $now . ')')];
        foreach (self::HEALTH_FIELDS as $key => $max) {
            if (array_key_exists($key, $info)) {
                $set[$key] = $info[$key] === null ? null : mb_substr((string) $info[$key], 0, $max);
            }
        }
        return DB::table('v2_ikev2_node')->where('server_id', $serverId)->whereNotNull('token_hash')->update($set) > 0;
    }

    public static function state(Server $server, ?IkeV2Node $node, ?int $now = null): string
    {
        $now ??= time();
        if (!$server->show) {
            return 'DISABLED';
        }
        if (!$node || !$node->token_hash) {
            return 'UNREGISTERED';
        }
        if (!$node->first_seen_at) {
            return 'REGISTERED';
        }
        if ($now - (int) $node->last_seen_at > self::HEARTBEAT_TTL) {
            return 'STALE';
        }
        return $node->applied_revision === null ? 'BOOTSTRAPPING' : 'HEALTHY';
    }

    /** Safe metadata for admins: never a token or a hash. */
    public static function getMetadata(int $serverId): array
    {
        $server = self::eligibleServer($serverId);
        $node = IkeV2Node::find($serverId);
        $now = time();
        return [
            'server_id' => (int) $server->id,
            'server' => ['id' => (int) $server->id, 'name' => $server->name, 'host' => $server->host, 'show' => (bool) $server->show],
            'state' => self::state($server, $node, $now),
            'token_active' => (bool) $node?->token_hash,
            'previous_token_valid_until' => $node && $node->token_prev_hash && $node->token_prev_expires_at > $now ? $node->token_prev_expires_at : null,
            'first_seen_at' => $node?->first_seen_at,
            'last_seen_at' => $node?->last_seen_at,
            'applied_revision' => $node?->applied_revision,
            'agent_version' => $node?->agent_version,
            'strongswan_version' => $node?->strongswan_version,
            'last_error' => $node?->last_error,
            'heartbeat_ttl' => self::HEARTBEAT_TTL,
        ];
    }

    private static function newToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(32));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
