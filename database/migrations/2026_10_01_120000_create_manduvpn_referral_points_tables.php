<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN RC: referral reward POINTS, kept strictly separate from cash commission (RC_CONTRACTS.md §4).
// Additive only - v2_user.commission_balance and v2_user.balance are NOT touched and NOT reused: both are
// implicit CNY cash with no expiry concept, and points are neither cash nor currency.
//
// v2_referral_reward.invitee_user_id is UNIQUE so "one reward per invitee" is enforced by the DATABASE, not by
// application logic: two concurrent first-orders cannot both grant.
// v2_points_ledger is append-only bookkeeping; a balance is the SUM of the rows that have not expired.
// Integer unix timestamps like the other v2_* tables.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_referral_reward')) {
            Schema::create('v2_referral_reward', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('inviter_user_id');
                // One reward per invited user, ever. This index IS the race-condition guard.
                $table->integer('invitee_user_id')->unique();
                $table->integer('trigger_order_id')->nullable();
                $table->integer('points');
                $table->string('status', 16)->default('granted'); // granted | revoked
                $table->integer('granted_at');
                $table->integer('expires_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index('inviter_user_id');
            });
        }
        if (!Schema::hasTable('v2_points_ledger')) {
            Schema::create('v2_points_ledger', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                // Signed: + accrual, - spend / expiry / negative adjustment.
                $table->integer('amount');
                // referral_reward | admin_adjust | expire | order_spend
                $table->string('type', 32);
                $table->string('source_type', 32)->nullable();
                $table->integer('source_id')->nullable();
                $table->integer('expires_at')->nullable();
                $table->integer('created_at');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_points_ledger');
        Schema::dropIfExists('v2_referral_reward');
    }
};
