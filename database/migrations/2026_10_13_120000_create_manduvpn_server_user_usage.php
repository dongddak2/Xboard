<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ManduVPN SERVER-QUOTA-1: per-user, per-server traffic accounting and the purchase anchor.
//
// WRITTEN AND NOT APPLIED. This migration has never been run anywhere. App\Services\ServerQuotaService
// guards the table's absence and reports usage as UNMEASURED rather than zero until it exists.
//
// APPLY ORDER, AND IT IS NOT OPTIONAL: THE SIGNUP WORK GOES FIRST.
// APPLYING OR DEPLOYING THIS BRANCH BEFORE THE SIGNUP EMAIL POLICY BREAKS THE ADMIN CONFIG PATH. The
// per-server cap is admin-edited through ConfigSave/ConfigController, and those edits sit on top of the
// signup policy's changes to the same two files, so this branch is cut from the signup branch and carries
// it. The constraint is written here rather than left in a review thread, because an ordering constraint
// that lives in a message is lost the moment someone deploys from the branch.
//
// ADDITIVE. One new table, and one new nullable column on v2_user. No existing column, index or constraint
// is altered, and no existing row's meaning changes.
//
// WHY A NEW TABLE RATHER THAN EXTENDING v2_stat_user, which is the obvious cheaper move:
//   1. v2_stat_user has no server_id and groups by server_rate, so two servers that share a rate MERGE INTO
//      ONE ROW. It cannot answer a per-server question even with a column bolted on.
//   2. Decisively: its bytes are already multiplied by the plan's traffic rate (StatUserJob:66-67,76-77),
//      and this cap is defined on RAW bytes. Dividing the multiplier back out would put rounding loss inside
//      a decision about someone's paid service, so the conversion is not reversible.
//   Raw bytes exist only at the ingestion boundary, UserService::trafficFetch(), which is where rows here
//   are written. The problem was a UNIT mismatch, not a missing column.
//
// THE KEY IS (user, server, node_type, period) AND IT KEYS ON THE ENTITLEMENT OWNER, NOT THE REPORTER.
// A child node reporting through a parent attributes to the CHILD's id, because the cap governs a user's
// right to use a particular node and that right attaches to the leaf. Keying on the reporter would let a
// relay topology change silently redistribute recorded usage and push someone over a cap without them using
// anything. This is a binding constraint on the relay work as well.
//   node_type is in the key because node ids are only unique per protocol - UniProxy already passes type
//   and id as separate arguments - so without it two different protocols' nodes could collide on one row.
//
// PERIODS ARE ANCHORED TO THE PURCHASE, NOT THE CALENDAR. period_start_at is anchor + n * 30 days. A
// first-of-month reset and an Asia/Seoul calendar basis are the SUPERSEDED rule and appear nowhere here.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_user_usage')) {
            Schema::create('v2_server_user_usage', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('user_id');
                $table->integer('server_id');
                // Node ids are unique only within a protocol, so the type is part of the identity.
                $table->string('node_type', 32);
                // Start of the 30-day period this row accounts for: anchor + n * 30 days, a unix instant.
                $table->integer('period_start_at');
                // RAW bytes as the node reported them. No plan traffic multiplier is ever applied here -
                // that is the whole reason this table exists rather than a column on v2_stat_user.
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
                // One row per (user, node, period). The UNIQUE constraint is what makes the accumulating
                // write an upsert rather than a race between concurrent reports.
                $table->unique(['user_id', 'server_id', 'node_type', 'period_start_at'], 'v2_server_user_usage_pair_period_unique');
                // The dashboard asks "this user, this period, across their entitled nodes".
                $table->index(['user_id', 'period_start_at'], 'v2_server_user_usage_user_period_index');
            });
        }

        if (!Schema::hasColumn('v2_user', 'quota_anchor_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                // The purchase anchor: written ONCE at first activation and never touched by an additive
                // renewal, so a renewal cannot hand out a fresh allowance. NULL means indeterminate, which
                // ServerQuotaService treats as unmeasured - it fails OPEN and shows UNKNOWN, by ruling,
                // because denying service to someone who has paid is worse than briefly not capping.
                $table->integer('quota_anchor_at')->nullable()->default(null);
            });

            $this->backfillAnchors();
        }
    }

    /**
     * ONE-TIME derivation, here and nowhere else.
     *
     * The anchor is deliberately not derived on read: a read-time derivation would make a PAST period's
     * boundary move whenever an unrelated order event changed, and for a cap that governs entitlement a
     * mutable past is disqualifying. So the derivation runs exactly once, at migration time, and the stored
     * value is authoritative and immutable afterwards.
     *
     * Order of preference: the earliest PAID order's created_at, else the user's own created_at as the
     * first-seen time. A user with neither keeps NULL and is reported as UNKNOWN rather than being denied.
     */
    private function backfillAnchors(): void
    {
        if (!Schema::hasTable('v2_order')) {
            return;
        }

        // Earliest COMPLETED order per user. Order::STATUS_COMPLETED (3) is what OrderService itself treats
        // as a real purchase everywhere it asks the question (:186, :200, :217), so the anchor uses the same
        // definition rather than inventing a wider one.
        // Deliberately excluded: STATUS_PROCESSING (1) is mid-activation and may still fail, and
        // STATUS_DISCOUNTED (4) is a credit rather than a purchase instant. A user whose only order is in
        // one of those states falls through to the first-seen fallback below.
        $earliest = DB::table('v2_order')
            ->selectRaw('user_id, MIN(created_at) AS first_paid_at')
            ->where('status', \App\Models\Order::STATUS_COMPLETED)
            ->groupBy('user_id');

        DB::table('v2_user')
            ->joinSub($earliest, 'o', fn ($join) => $join->on('o.user_id', '=', 'v2_user.id'))
            ->whereNull('v2_user.quota_anchor_at')
            ->update(['v2_user.quota_anchor_at' => DB::raw('o.first_paid_at')]);

        // Everyone else falls back to first-seen, so an existing user has a defined starting point rather
        // than being treated as unmeasurable forever.
        DB::table('v2_user')
            ->whereNull('quota_anchor_at')
            ->whereNotNull('created_at')
            ->update(['quota_anchor_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_user_usage');
        if (Schema::hasColumn('v2_user', 'quota_anchor_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('quota_anchor_at');
            });
        }
    }
};
