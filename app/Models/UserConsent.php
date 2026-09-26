<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN RC: one signup-consent record per (user, consent version). Written only by ConsentService
 * inside the signup transaction; nothing is mass-assignable. There is deliberately no relation on User so
 * the existing user/admin serialization cannot pick it up.
 */
class UserConsent extends Model
{
    protected $table = 'v2_user_consent';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['*'];
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'agreed_identity' => 'boolean',
        'agreed_account_sharing' => 'boolean',
        'agreed_payment_refund' => 'boolean',
        'agreed_at' => 'integer',
        'created_at' => 'integer',
    ];
}
