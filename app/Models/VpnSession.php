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

    public const STATUS_ACTIVE = 'ACTIVE';
}
