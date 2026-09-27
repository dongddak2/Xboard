<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\BasicServerAssignmentService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

/**
 * ManduVPN: the Hysteria2 connection profile the app needs to raise a tunnel.
 *
 * GET /api/v1/user/server/hysteria2/profile?server_id=N   (authenticated)
 *
 * WHY A DEDICATED ENDPOINT AND NOT THE SUBSCRIPTION URL. The alternative was reusing
 * GET /api/v1/client/subscribe, which already emits a working hysteria2:// line. It was rejected for four
 * measured reasons:
 *  1. General::buildHysteria emits sni / security / obfs / obfs-password / mport / insecure and DROPS
 *     bandwidth.up and bandwidth.down. Those two values exist in protocol_settings and SingBox, ClashMeta,
 *     Stash, Surge and Shadowrocket all carry them - only the plain URI form does not. Getting them over
 *     the subscription therefore means asking for a third-party client's config format, which is not our
 *     contract and can change without notice.
 *  2. The subscription authenticates with v2_user.token read from a QUERY PARAMETER (Middleware\Client),
 *     not a bearer token, so a long-lived credential ends up in access logs, proxy logs and referrers.
 *  3. Eligibility over the subscription is all-or-nothing per user. Here it is per node and fail-closed.
 *  4. The app already speaks this shape for IKEv2 (Ikev2ProfileController). One response shape, not two.
 *
 * NO SHARED FIELDS WITH THE IKEv2 PROFILE, ON PURPOSE. This controller deliberately declares its own
 * SCHEMA and its own error identifiers and reads none of Ikev2ProfileController's constants. The two
 * profiles are separate contracts: making one depend on the other means an IKEv2 change can silently move
 * the Hysteria2 response. There is no shared base class for the same reason.
 *
 * WHOSE CREDENTIAL: the caller's, always. The user is taken from the bearer token only - there is no
 * user_id / email / username parameter, so no request can reach another account. `server_id` selects a
 * NODE, never a user.
 *
 * ELIGIBILITY IS FAIL-CLOSED: type = hysteria AND protocol_settings.version = 2 AND show = 1 AND
 * parent_id IS NULL AND group_ids contains the user's group_id. A LEGACY-tier user additionally only ever
 * gets their own assigned_server_id, which is the single-server entitlement. An ineligible or unknown
 * server_id is refused with 404 - not 403 - so this cannot be used to enumerate which nodes exist.
 *
 * WHY version = 2 IS AN ELIGIBILITY RULE RATHER THAN A FIELD. General::buildHysteria returns an empty
 * string when version !== 2, i.e. the subscription silently omits Hysteria 1 nodes. Reporting a Hysteria 1
 * node here with `version: 1` would hand the app a profile it cannot use; refusing it with 404 keeps the
 * same outcome the subscription already has, but says so.
 *
 * SECRET HANDLING: the response carries the auth string plaintext because Hysteria2 requires the client to
 * hold it. It is therefore sent only over an authenticated single-node read, with Cache-Control: no-store,
 * and it is never written to a log, an error message or an exception.
 *
 * CREDENTIAL LIFETIME - STATED PLAINLY BECAUSE THIS ENDPOINT DOES NOT FIX IT. auth is v2_user.uuid. It is
 * long-lived: there is no expiry and no renewal cycle, and the only rotation path is /user/resetSecurity,
 * which also rotates v2_user.token. A short-lived per-user Hysteria2 credential would need its own table,
 * i.e. a migration, which is out of scope here. What this endpoint improves is the TRANSPORT (bearer token,
 * no-store, nothing in the URL), not the lifetime. Do not read it as having improved the lifetime.
 *
 * NOT VERIFIED AT RUNTIME: this code is syntax-checked and reviewed only. No tunnel has been raised with
 * it, and three inputs are unmeasured on every host - the eligible node's protocol_settings.version, its
 * port range (whether `ports` is populated), and its obfs settings.
 */
class Hysteria2ProfileController extends Controller
{
    public const SCHEMA = 'manduvpn.hysteria2.profile.v1';

    /** The only Hysteria version this stack emits a usable config for. See the class note. */
    public const SUPPORTED_VERSION = 2;

    public function profile(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer|min:1',
        ], [
            'server_id.required' => 'server_id is required',
            'server_id.integer' => 'server_id must be an integer',
        ]);

        // Identity comes from the token and nowhere else.
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([403, __('Unauthorized')], null, 'HY2_UNAUTHORIZED');
        }

        // An expired / banned / out-of-traffic subscription gets no credential. Same gate the node list uses.
        if (!(new UserService())->isAvailable($user)) {
            return $this->fail([403, '이용 중인 구독이 없어 VPN 접속 정보를 제공할 수 없습니다'], null, 'HY2_SUBSCRIPTION_INACTIVE');
        }

        $server = $this->eligibleServerFor($user, (int) $request->input('server_id'));
        if (!$server) {
            // Deliberately indistinguishable from "no such server": no enumeration.
            return $this->fail([404, '해당 서버에 접속할 권한이 없습니다'], null, 'HY2_SERVER_NOT_ELIGIBLE');
        }

        // Reuse the allocation the subscription path uses, so a port range yields the same shape here:
        // `port` becomes one port inside the range and `ports` keeps the range itself (subscribe calls this
        // the mport parameter). Re-deriving it locally would be a second, divergent implementation.
        $server->handlePortAllocation();

        $settings = $server->protocol_settings;
        $obfsOpen = (bool) data_get($settings, 'obfs.open');

        return response([
            'data' => [
                'schema' => self::SCHEMA,
                'server' => [
                    'id' => (int) $server->id,
                    'name' => (string) $server->name,
                    'host' => (string) $server->host,
                    'port' => (int) $server->port,
                    // Null when the node has a single port. A range is reported as the original string.
                    'ports' => $server->ports !== null ? (string) $server->ports : null,
                ],
                'tls' => [
                    'server_name' => data_get($settings, 'tls.server_name'),
                    'allow_insecure' => (bool) data_get($settings, 'tls.allow_insecure'),
                ],
                'obfs' => [
                    'open' => $obfsOpen,
                    'type' => $obfsOpen ? data_get($settings, 'obfs.type') : null,
                    // Withheld while obfs is off: the client has no use for it, and a secret that is not
                    // needed is not handed out. Turning obfs on is what makes it appear.
                    'password' => $obfsOpen ? data_get($settings, 'obfs.password') : null,
                ],
                'bandwidth' => [
                    'up' => data_get($settings, 'bandwidth.up'),
                    'down' => data_get($settings, 'bandwidth.down'),
                ],
                'credential' => [
                    // Long-lived. See CREDENTIAL LIFETIME in the class note before relying on this.
                    'auth' => (string) $user->uuid,
                ],
            ],
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * The requested server, but only if this user may actually connect to it. Returns null otherwise -
     * callers must treat null as "refuse", never as "fall back to some other node".
     */
    private function eligibleServerFor(User $user, int $serverId): ?Server
    {
        $query = Server::where('id', $serverId)
            ->where('type', Server::TYPE_HYSTERIA)
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

        // Checked in PHP, not SQL, on purpose: the protocol_settings accessor applies the schema defaults
        // (version defaults to 2 when the key is absent), so a JSON path query would disagree with what
        // General::buildHysteria actually sees for a row that never stored the key.
        //
        // STRICT ===, AND NO CAST OF OUR OWN. A misconfigured node must fall out as 404 rather than be
        // served through a comparison loose enough to hide the defect. Note what this does and does not
        // protect against: Server::PROTOCOL_CONFIGURATIONS['hysteria']['version'] is type 'integer' and
        // castValueWithConfig does `(int) $value`, so the accessor has ALREADY turned a stored "2" into
        // int 2 by the time we look - and that same cast turns "2abc" into 2. The looseness that matters
        // therefore lives in the cast, not in the operator, and no comparison here can undo it. Writing
        // `(int) data_get(...)` would add a second cast of our own and make the operator meaningless, so
        // it is deliberately absent.
        if (data_get($server->protocol_settings, 'version') !== self::SUPPORTED_VERSION) {
            return null;
        }

        return $server;
    }
}
