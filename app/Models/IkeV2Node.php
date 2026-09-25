<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN PP-C4-1B: IKEv2 node identity/health row (1:1 with v2_server). Written only by IkeV2NodeService (nothing is
 * mass-assignable); token hashes are never serialized. Deliberately NO relation on Server, so existing server
 * serialization cannot pick it up.
 */
class IkeV2Node extends Model
{
    protected $table = 'v2_ikev2_node';
    protected $primaryKey = 'server_id';
    public $incrementing = false;
    protected $keyType = 'int';
    protected $dateFormat = 'U';
    protected $guarded = ['*'];
    protected $hidden = ['token_hash', 'token_prev_hash'];
    protected $casts = [
        'server_id' => 'integer',
        'token_prev_expires_at' => 'integer',
        'first_seen_at' => 'integer',
        'last_seen_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
