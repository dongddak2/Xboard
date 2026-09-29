<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN HY2-CRED-1: the short-lived Hysteria2 credential, one row per user.
 *
 * Written only by Hy2CredentialService (nothing is mass-assignable) and the encrypted secret is never
 * serialized. There is deliberately NO relation on User, so existing user/admin serialization cannot pick it
 * up - the same rule VpnCredential (IKEv2) follows. That table is a different table and is never touched here.
 */
class Hy2Credential extends Model
{
    protected $table = 'v2_vpn_hy2_credential';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    protected $dateFormat = 'U';
    protected $guarded = ['*'];
    protected $hidden = ['secret_enc'];
    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'integer',
        'issued_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
