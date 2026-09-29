<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN stage C: a server-issued VPN session (RC_CONTRACTS.md §23.19-a). Written only by
 * VpnSessionController - nothing is mass-assignable.
 *
 * A row here records that a session WAS ISSUED. It is not a limit counter: concurrent-connection enforcement
 * stays with the node's live-IP count (UserOnlineService), so that "how many devices are connected" has
 * exactly one definition. See the migration note.
 */
class VpnSession extends Model
{
    protected $table = 'v2_vpn_session';
    protected $dateFormat = 'U';
    protected $guarded = ['*'];
    protected $casts = [
        'user_id' => 'integer',
        'server_id' => 'integer',
        'connected_at' => 'integer',
        'last_heartbeat_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * STORED STATUS IS NOT THE SAME VOCABULARY AS THE WIRE STATUS. The app distinguishes only two values -
     * 'ACTIVE' and 'REVOKED' (vpn_session.dart:32-33) - so it cannot tell WHY a session stopped being usable.
     * The four values below keep that reason on the server, where it is worth having, and
     * VpnSessionController::wireStatus() collapses them to the two the app understands. Adding a fifth stored
     * status therefore needs no app change; adding a wire value does.
     */
    public const STATUS_ACTIVE = 'ACTIVE';

    /** The client released it (DELETE /vpn/sessions/{id}). A clean end, not a failure. */
    public const STATUS_ENDED = 'ENDED';

    /** No heartbeat within the TTL. Set by the lazy sweep, not by a scheduler - see the controller. */
    public const STATUS_EXPIRED = 'EXPIRED';

    /** Entitlement was lost while connected (subscription lapsed, banned, out of traffic). */
    public const STATUS_REVOKED = 'REVOKED';
}
