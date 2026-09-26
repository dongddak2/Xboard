<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN RC: one referral POINTS reward per invited user (RC_CONTRACTS.md §4).
 * Written only by PointsService; nothing is mass-assignable. invitee_user_id is UNIQUE in the migration, so
 * "one reward per invitee" is guaranteed by the database rather than by a read-then-write check.
 * Points are not currency: this row never touches v2_user.balance or v2_user.commission_balance.
 * There is deliberately no relation on User, so existing user/admin serialization cannot pick it up.
 */
class ReferralReward extends Model
{
    protected $table = 'v2_referral_reward';
    protected $dateFormat = 'U';
    protected $guarded = ['*'];

    public const STATUS_GRANTED = 'granted';
    public const STATUS_REVOKED = 'revoked';

    protected $casts = [
        'id' => 'integer',
        'inviter_user_id' => 'integer',
        'invitee_user_id' => 'integer',
        'trigger_order_id' => 'integer',
        'points' => 'integer',
        'granted_at' => 'integer',
        'expires_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}
