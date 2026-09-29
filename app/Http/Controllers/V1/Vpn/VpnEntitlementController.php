<?php

namespace App\Http\Controllers\V1\Vpn;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\User\Hysteria2ProfileController;
use App\Models\Server;
use App\Models\User;
use App\Services\BasicServerAssignmentService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

/**
 * ManduVPN: what this account is allowed to do, as the app's own Entitlement model reads it.
 *
 * GET /api/v1/vpn/entitlements   (authenticated)
 *
 * WHAT THIS IS FOR, AND WHAT IT IS NOT FOR. Entitlement.fromJson (entitlement.dart:26) feeds a UI hint - the
 * app's own doc comment says the real decision is always re-verified through POST /vpn/sessions. So nothing
 * here grants anything. Its job is to stop the app DISPLAYING an entitlement the server would refuse, which is
 * the defect the app had until 2026-09-27: on failure it substituted `fallbackIkev2Only` and showed a
 * plausible "IKEv2, 1 device" that nobody had granted.
 *
 * ALL FIVE FIELDS ARE OPTIONAL IN THE APP, so this endpoint's risk is not omission - it is overstatement.
 * Every value below is therefore derived from the same predicates the endpoints that actually grant use, never
 * from a plan description or a default.
 *
 * allowedProtocols IS COMPUTED FROM REAL NODES, NOT FROM THE PLAN. It lists a protocol only if this user has
 * at least one node they could actually be issued a session for - the same four predicates as
 * VpnSessionController plus the Hysteria version check, reading SUPPORTED_VERSION from the profile controller
 * for the same reason that one does: if these two disagree, the app offers a protocol the session route then
 * refuses. A plan row saying "hysteria2" while no Hysteria 2 node is visible to the group would be exactly
 * that overstatement.
 *
 * ONE LIMIT, REPORTED TWICE, ON PURPOSE. The app asks for deviceLimit AND concurrentConnectionLimit, but this
 * stack has one number: `v2_user.device_limit`, which the node compares against its live IP count (limiter.go
 * compares deviceLimit to aliveIp). Inventing a second, different value here would create a second definition
 * of "how many at once" that nothing enforces. Both fields carry the same number.
 *
 * NULL device_limit MEANS UNLIMITED AND THE APP CANNOT SAY THAT: deviceLimit is `int? ?? 1`, so no value means
 * "no limit". The key is OMITTED in that case, which makes the app display 1. That understates the
 * entitlement, and understating is the safe direction for a hint - a user seeing "1 device" when they may use
 * four is a support question, while the reverse is a broken connection they were told to expect. It is not a
 * lie the server tells; it is a shape the app cannot express, and it is written down here so nobody "fixes" it
 * by sending a sentinel like 0 or 9999.
 *
 * allowedServerGroups CARRIES GROUP IDS AS STRINGS, not names. Nothing in the app consumes the field (grep:
 * zero reads outside entitlement.dart), so it exists to be displayed or logged; ids are stable while
 * v2_server_group.name is free text an admin can rename at any time.
 *
 * A LAPSED SUBSCRIPTION IS REFUSED, NOT DESCRIBED. 403 + SUBSCRIPTION_EXPIRED, the same identifier and status
 * the session route uses, so the app renders one sentence from one vocabulary. This is deliberately not "200
 * with everything empty": the app's EntitlementApi throws on 4xx and its caller must then treat entitlement as
 * UNKNOWN, whereas an empty 200 is a positive statement that the account has no entitlements - a different
 * fact.
 *
 * NOT WIRED IN THE APP YET. EntitlementApi is instantiated nowhere (zero references), so this route answers
 * nobody today. It is built now because the contract is already written on the app side, and a route that
 * exists cannot be the thing that blocks wiring it.
 *
 * NOT VERIFIED AT RUNTIME beyond its regression suite, which runs on SQLite. The group_ids match compiles
 * per-engine (json_each on SQLite, JSON_CONTAINS on MySQL), so allowedProtocols is the one field here whose
 * value is engine-dependent.
 */
class VpnEntitlementController extends Controller
{
    /** Node type to the app's protocol wire value (vpn_status.dart:56). Types not here cannot be tunnelled. */
    private const NODE_TYPE_TO_PROTOCOL = [
        Server::TYPE_IKEV2 => 'ikev2',
        Server::TYPE_HYSTERIA => 'hysteria2',
    ];

    public function show(Request $request)
    {
        // Identity comes from the token and nowhere else: there is no user parameter on this route.
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([403, __('Unauthorized')], null, 'AUTH_FAILED');
        }

        if (!(new UserService())->isAvailable($user)) {
            return $this->fail([403, '이용 중인 구독이 없어 VPN 권한을 조회할 수 없습니다'], null, 'SUBSCRIPTION_EXPIRED');
        }

        $data = [
            'allowedProtocols' => $this->allowedProtocols($user),
            'allowedServerGroups' => $user->group_id === null ? [] : [(string) $user->group_id],
            // isAvailable() has already refused expired_at = 0 and every past timestamp, so this is either a
            // future date or null (null = never expires). No 1970 date can reach the app from here.
            'subscriptionExpiresAt' => $user->expired_at === null ? null : date('c', (int) $user->expired_at),
        ];

        // Omitted rather than guessed when unlimited - see the class note.
        if ($user->device_limit !== null) {
            $data['deviceLimit'] = (int) $user->device_limit;
            $data['concurrentConnectionLimit'] = (int) $user->device_limit;
        }

        return response(['data' => $data])
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * The protocols this user has at least one connectable node for. An empty list is a valid answer and means
     * "nothing you can connect to right now" - callers must not read it as "unknown".
     */
    private function allowedProtocols(User $user): array
    {
        $query = Server::whereIn('type', array_keys(self::NODE_TYPE_TO_PROTOCOL))
            ->where('show', true)
            ->whereNull('parent_id')
            // ManduVPN NG-0: group_ids is a JSON integer array.
            ->whereJsonContains('group_ids', (int) $user->group_id);

        // LEGACY tier is entitled to exactly one server; honour that here too.
        if (ServerService::serviceTierOf($user) === BasicServerAssignmentService::TIER) {
            if ($user->assigned_server_id === null) {
                return [];
            }
            $query->where('id', (int) $user->assigned_server_id);
        }

        $protocols = [];
        foreach ($query->get() as $server) {
            // Same rule, same constant, as the session route: a Hysteria 1 node is not connectable by this app,
            // so offering 'hysteria2' because such a node exists would be the overstatement described above.
            if ($server->type === Server::TYPE_HYSTERIA
                && data_get($server->protocol_settings, 'version') !== Hysteria2ProfileController::SUPPORTED_VERSION) {
                continue;
            }
            $protocols[self::NODE_TYPE_TO_PROTOCOL[$server->type]] = true;
        }

        // array_keys, so this encodes as a JSON array and not an object.
        return array_keys($protocols);
    }
}
