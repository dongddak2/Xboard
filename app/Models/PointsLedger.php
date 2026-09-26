<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ManduVPN RC: append-only points ledger (RC_CONTRACTS.md §4). `amount` is signed: + accrual, - spend /
 * expiry / negative adjustment. A balance is the SUM of the rows that have not expired - rows are never
 * updated or deleted, so history stays auditable.
 * Written only by PointsService; nothing is mass-assignable. Unit is "P" - never rendered as KRW or CNY.
 */
class PointsLedger extends Model
{
    protected $table = 'v2_points_ledger';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['*'];

    public const TYPE_REFERRAL_REWARD = 'referral_reward';
    public const TYPE_ADMIN_ADJUST = 'admin_adjust';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_ORDER_SPEND = 'order_spend';

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'integer',
        'source_id' => 'integer',
        'expires_at' => 'integer',
        'created_at' => 'integer',
    ];
}
