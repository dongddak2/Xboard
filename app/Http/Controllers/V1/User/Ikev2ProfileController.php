<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\BasicServerAssignmentService;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\VpnCredentialService;
use Illuminate\Http\Request;

/**
 * ManduVPN APP-BLOCK-1: the IKEv2 connection profile the app needs to raise a tunnel (RC_CONTRACTS.md §4.2).
 *
 * GET /api/v1/user/server/ikev2/profile?server_id=N   (authenticated)
 *
 * WHY THIS EXISTS: NodeResource returns id/type/version/name/rate/tags/is_online/cache_key/last_check_at and
 * therefore no connection address, and VpnCredentialService had no user-facing consumer at all - only the
 * node-side IkeV2NodeApiController. So the app could list nodes but never connect to one. This endpoint adds no
 * storage and no issuance logic of its own: it reuses VpnCredentialService and reads v2_server.
 *
 * ONE NODE PER CALL, BY DESIGN. There is deliberately no bulk variant and NodeResource is left untouched, so a
 * list response can never carry a secret. Same principle as v2_payment.config: the list says a thing exists, the
 * value is only ever handed over by an explicit single-item read.
 *
 * WHOSE CREDENTIAL: the caller's, always. The user is taken from the bearer token only - there is no user_id /
 * email / username parameter, so no request can reach another account. `server_id` selects a NODE, never a user.
 *
 * ELIGIBILITY IS FAIL-CLOSED and matches BasicServerAssignmentService::eligibleServerIds() exactly:
 * type = ikev2 AND show = 1 AND parent_id IS NULL AND group_ids contains the user's group_id. A LEGACY-tier user
 * additionally only ever gets their own assigned_server_id, which is the single-server entitlement - returning
 * any other eligible node here would be a way around it. An ineligible or unknown server_id is refused with 404
 * and no credential is created or returned. 404 rather than 403 so this cannot be used to enumerate which nodes
 * exist.
 *
 * SECRET HANDLING: the response carries the plaintext secret because IKEv2 EAP-MSCHAPv2 requires the client to
 * hold it - this stack has no token-exchange alternative. It is therefore sent only over an authenticated
 * single-node read, with Cache-Control: no-store, and it is never written to a log, an error message or an
 * exception. VpnCredentialService::ensure() logs the user_id only.
 *
 * THREE JUDGEMENT CALLS (LEAD left these to me; the six constraints are all satisfied):
 *  1. No rotation on read. One credential per user is shared by all of that user's devices and is installed in
 *     the node's ipsec.secrets, so rotating on every fetch would break every already-configured device and race
 *     the node's own pull cycle. Rotation stays the explicit, separate VpnCredentialService::rotate().
 *  2. No per-session issuance. v2_vpn_credential is keyed by user_id - one row per user - and per-session
 *     credentials would need a new table, which is exactly the new storage LEAD ruled out.
 *  3. ensure() is called here, so the credential is created on first fetch. That is the designed entry point
 *     ("creates only when explicitly called, no bulk / scheduled creation") and it saves the app a separate
 *     provisioning call. It is idempotent, so repeat fetches return the same values.
 *
 * NOT VERIFIED AT RUNTIME: there are currently 0 eligible IKEv2 nodes on the test server (the only ikev2 node has
 * show = 0), so this path cannot be exercised end to end until that is fixed (OWNER_DECISIONS A-2). This code is
 * syntax-checked and reviewed only - no tunnel has been raised with it.
 */
class Ikev2ProfileController extends Controller
{
    public const SCHEMA = 'manduvpn.ikev2.profile.v1';

    /**
     * IKEv2 is fixed by the protocol to UDP 500 (IKE) and 4500 (NAT traversal). The generic v2_server.port /
     * server_port columns are NOT used by the ikev2 path - UniProxyController passes only host, vpn_pool, dns and
     * mtu to the node - so reporting those columns here would be inventing a number. These two constants are
     * reported instead, clearly as protocol constants rather than per-node configuration.
     */
    public const PORT_IKE = 500;
    public const PORT_NAT_T = 4500;

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
            return $this->fail([403, __('Unauthorized')], null, 'IKEV2_UNAUTHORIZED');
        }

        // An expired / banned / out-of-traffic subscription gets no credential. Same gate the node list uses.
        if (!(new UserService())->isAvailable($user)) {
            return $this->fail([403, __('Active subscription required to issue VPN connection info')], null, 'IKEV2_SUBSCRIPTION_INACTIVE');
        }

        $server = $this->eligibleServerFor($user, (int) $request->input('server_id'));
        if (!$server) {
            // Deliberately indistinguishable from "no such server": no enumeration, no credential created.
            return $this->fail([404, __('You are not allowed to connect to this server')], null, 'IKEV2_SERVER_NOT_ELIGIBLE');
        }

        VpnCredentialService::ensure($user);
        $plain = VpnCredentialService::get($user);
        if ($plain === null) {
            // ensure() just returned a row, so this only happens if it vanished between the two calls.
            return $this->fail([500, __('VPN connection info could not be read')], null, 'IKEV2_CREDENTIAL_UNAVAILABLE');
        }

        return response([
            'data' => [
                'schema' => self::SCHEMA,
                'server' => [
                    'id' => (int) $server->id,
                    'name' => (string) $server->name,
                    // The connection address. Also the identity the client validates the server certificate
                    // against - no separate remote_id is stored anywhere, so the app must use this host.
                    'host' => (string) $server->host,
                    'ports' => [
                        'ike' => self::PORT_IKE,
                        'nat_t' => self::PORT_NAT_T,
                    ],
                ],
                'credential' => [
                    'username' => $plain['username'],
                    'secret' => $plain['secret'],
                ],
                // Unix integer, like every other v2_* timestamp. Lets the app notice a rotation and re-fetch.
                'rotated_at' => (int) $plain['rotated_at'],
            ],
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * The requested server, but only if this user may actually connect to it. Returns null otherwise - callers
     * must treat null as "refuse", never as "fall back to some other node".
     */
    private function eligibleServerFor(User $user, int $serverId): ?Server
    {
        $query = Server::where('id', $serverId)
            ->where('type', 'ikev2')
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

        return $query->first();
    }
}
