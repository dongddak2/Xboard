<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN PP-C4-1A: one IKEv2 VPN credential per user. Written only by VpnCredentialService (nothing is
 * mass-assignable) and the encrypted secret is never serialized. There is deliberately NO relation on User,
 * so existing user/admin serialization cannot pick it up.
 */
class VpnCredential extends Model
{
    protected $table = 'v2_vpn_credential';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    protected $dateFormat = 'U';
    protected $guarded = ['*'];
    protected $hidden = ['secret_enc'];
    protected $casts = [
        'user_id' => 'integer',
        'rotated_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
