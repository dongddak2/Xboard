<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Http\Middleware\IkeV2NodeAuth;
use App\Models\IkeV2Node;
use App\Services\IkeV2NodeService;
use App\Services\ServerService;
use App\Services\VpnCredentialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN PP-C4-1C: IKEv2 node API. IkeV2NodeAuth has already authenticated the node (request attribute ikev2_node).
 * Responses are plain versioned JSON documents (no {status,data} envelope, like UniProxy/user), always no-store.
 *
 * users: 403 NODE_DISABLED when show=0. Membership = PP-C1 ServerService::getAvailableUsers(group_ids, node id)
 *   (not re-implemented) joined to C4-1A v2_vpn_credential; a user without a credential is excluded (never created
 *   here). Sorted by user id. Any undecryptable / malformed / duplicate credential -> 500 CREDENTIAL_INVALID for the
 *   whole list (never a partial 200). Secrets are decrypted with the same Laravel Crypt used by VpnCredentialService
 *   (one batch query instead of one get() per user).
 *   revision = sha256("manduvpn.ikev2.users.v1\n" . json_encode({"node_id":N,"users":[[id,username,secret,
 *   expires_at,device_limit],...]}, UNESCAPED_SLASHES|UNESCAPED_UNICODE)), users in id order, ints/null as-is.
 *   ETag = "<revision>"; If-None-Match (list, W/ allowed) matching -> 304 without body.
 * report: strict manduvpn.ikev2.report.v1 (see report()); unknown keys are ignored (never read or stored). Accepted
 *   for a disabled node (observability) and never returns credentials. Traffic is validated only: accounting is
 *   deferred (UserService::trafficFetch dispatches queued jobs and the strongSwan counter semantics are not defined).
 * ping: stores optional agent_version / strongswan_version query values; works while show=0 (bootstrap).
 */
class IkeV2NodeApiController extends Controller
{
    public const USERS_SCHEMA = 'manduvpn.ikev2.users.v1';
    public const PING_SCHEMA = 'manduvpn.ikev2.ping.v1';
    public const REPORT_SCHEMA = 'manduvpn.ikev2.report.v1';
    public const REPORT_ACK_SCHEMA = 'manduvpn.ikev2.report.ack.v1';
    public const VERSION_PATTERN = '/^[\x20-\x7E]{1,64}$/';
    public const REPORT_MAX_BYTES = 2097152;
    public const REPORT_MAX_ITEMS = 10000;
    public const MAX_BYTES_COUNTER = 9007199254740991;

    public function ping(Request $request)
    {
        $server = $request->attributes->get('ikev2_node');
        $info = [];
        foreach (['agent_version', 'strongswan_version'] as $key) {
            $value = $request->query($key);
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || !preg_match(self::VERSION_PATTERN, $value)) {
                return IkeV2NodeAuth::deny(422, 'PING_INVALID');
            }
            $info[$key] = $value;
        }
        if ($info) {
            IkeV2NodeService::recordHeartbeat((int) $server->id, $info);
        }
        return self::json([
            'schema' => self::PING_SCHEMA,
            'node_id' => (int) $server->id,
            'server_time' => time(),
            'show' => (bool) $server->show,
            'state' => IkeV2NodeService::state($server, IkeV2Node::find($server->id)),
        ]);
    }

    public function users(Request $request)
    {
        $server = $request->attributes->get('ikev2_node');
        if (!$server->show) {
            return IkeV2NodeAuth::deny(403, 'NODE_DISABLED');
        }
        $ids = ServerService::getAvailableUsers((array) $server->group_ids, (int) $server->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $part = DB::table('v2_user as u')->join('v2_vpn_credential as c', 'c.user_id', '=', 'u.id')->whereIn('u.id', $chunk)
                ->get(['u.id', 'c.username', 'c.secret_enc', 'u.expired_at', 'u.device_limit']);
            foreach ($part as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => (int) $a->id <=> (int) $b->id);
        $users = [];
        foreach ($rows as $row) {
            try {
                $secret = Crypt::decryptString($row->secret_enc);
            } catch (\Throwable $e) {
                $secret = '';
            }
            if (!preg_match(VpnCredentialService::USERNAME_PATTERN, (string) $row->username) || !preg_match(VpnCredentialService::SECRET_PATTERN, $secret)) {
                Log::error('ManduVPN IKEv2 users list refused: invalid stored credential', ['server_id' => (int) $server->id, 'user_id' => (int) $row->id]);
                return IkeV2NodeAuth::deny(500, 'CREDENTIAL_INVALID');
            }
            $users[] = [
                'id' => (int) $row->id,
                'username' => $row->username,
                'secret' => $secret,
                'expires_at' => $row->expired_at === null ? null : (int) $row->expired_at,
                'device_limit' => $row->device_limit === null ? null : (int) $row->device_limit,
            ];
        }
        if (count(array_unique(array_column($users, 'username'))) !== count($users)) {
            return IkeV2NodeAuth::deny(500, 'CREDENTIAL_INVALID');
        }
        $revision = self::revision((int) $server->id, $users);
        if (self::etagMatches((string) $request->header('If-None-Match', ''), $revision)) {
            return response('', 304)->header('ETag', '"' . $revision . '"')->header('Cache-Control', 'no-store');
        }
        return self::json([
            'schema' => self::USERS_SCHEMA,
            'node_id' => (int) $server->id,
            'generated_at' => time(),
            'revision' => $revision,
            'user_count' => count($users),
            'users' => $users,
        ])->header('ETag', '"' . $revision . '"');
    }

    /**
     * Body (JSON object): schema = manduvpn.ikev2.report.v1; node_id int = authenticated node (else 403 NODE_MISMATCH);
     * generated_at int in [1600000000, now+300]; agent_version / strongswan_version printable ASCII 1..64;
     * applied_revision null or 64 lowercase hex; last_error null or printable ASCII 0..255;
     * sessions: list (<= 10000) of {username: VPN username, connected_at: int 0..now+300};
     * traffic: list (<= 10000) of {username: VPN username, rx_bytes, tx_bytes: int 0..2^53-1}.
     * JSON ints only (no numeric strings / floats). Any violation -> 422 REPORT_INVALID, nothing stored.
     */
    public function report(Request $request)
    {
        $server = $request->attributes->get('ikev2_node');
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::REPORT_MAX_BYTES) {
            return IkeV2NodeAuth::deny(413, 'REPORT_TOO_LARGE');
        }
        $d = json_decode($raw, true);
        $now = time();
        $int = fn ($v, $min, $max) => is_int($v) && $v >= $min && $v <= $max;
        $str = fn ($v, $re) => is_string($v) && preg_match($re, $v) === 1;
        $list = fn ($v) => is_array($v) && array_is_list($v) && count($v) <= self::REPORT_MAX_ITEMS;
        if (!is_array($d) || array_is_list($d) || ($d['schema'] ?? null) !== self::REPORT_SCHEMA || !$int($d['node_id'] ?? null, 1, PHP_INT_MAX)) {
            return IkeV2NodeAuth::deny(422, 'REPORT_INVALID');
        }
        if ($d['node_id'] !== (int) $server->id) {
            return IkeV2NodeAuth::deny(403, 'NODE_MISMATCH');
        }
        $ok = $int($d['generated_at'] ?? null, 1600000000, $now + 300)
            && $str($d['agent_version'] ?? null, self::VERSION_PATTERN)
            && $str($d['strongswan_version'] ?? null, self::VERSION_PATTERN)
            && array_key_exists('applied_revision', $d) && ($d['applied_revision'] === null || $str($d['applied_revision'], '/^[0-9a-f]{64}$/'))
            && array_key_exists('last_error', $d) && ($d['last_error'] === null || $str($d['last_error'], '/^[\x20-\x7E]{0,255}$/'))
            && $list($d['sessions'] ?? null) && $list($d['traffic'] ?? null);
        if ($ok) {
            foreach ($d['sessions'] as $s) {
                $ok = $ok && is_array($s) && $str($s['username'] ?? null, VpnCredentialService::USERNAME_PATTERN) && $int($s['connected_at'] ?? null, 0, $now + 300);
            }
            foreach ($d['traffic'] as $t) {
                $ok = $ok && is_array($t) && $str($t['username'] ?? null, VpnCredentialService::USERNAME_PATTERN)
                    && $int($t['rx_bytes'] ?? null, 0, self::MAX_BYTES_COUNTER) && $int($t['tx_bytes'] ?? null, 0, self::MAX_BYTES_COUNTER);
            }
        }
        if (!$ok) {
            return IkeV2NodeAuth::deny(422, 'REPORT_INVALID');
        }
        IkeV2NodeService::recordHeartbeat((int) $server->id, [
            'agent_version' => $d['agent_version'],
            'strongswan_version' => $d['strongswan_version'],
            'applied_revision' => $d['applied_revision'],
            'last_error' => $d['last_error'],
        ]);
        return self::json([
            'schema' => self::REPORT_ACK_SCHEMA,
            'node_id' => (int) $server->id,
            'accepted' => true,
            'sessions' => count($d['sessions']),
            'traffic_entries' => count($d['traffic']),
            'traffic_accounting' => 'deferred',
        ]);
    }

    public static function revision(int $nodeId, array $users): string
    {
        $canonical = array_map(fn ($u) => [$u['id'], $u['username'], $u['secret'], $u['expires_at'], $u['device_limit']], $users);
        return hash('sha256', self::USERS_SCHEMA . "\n" . json_encode(['node_id' => $nodeId, 'users' => $canonical], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function etagMatches(string $header, string $revision): bool
    {
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if (str_starts_with($tag, 'W/')) {
                $tag = substr($tag, 2);
            }
            if (trim($tag, '"') === $revision) {
                return true;
            }
        }
        return false;
    }

    private static function json(array $data)
    {
        return response()->json($data, 200, [], JSON_UNESCAPED_SLASHES)->header('Cache-Control', 'no-store');
    }
}
