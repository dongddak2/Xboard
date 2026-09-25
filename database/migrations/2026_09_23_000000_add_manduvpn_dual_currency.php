<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ManduVPN dual-currency support, added additively on top of stock xboard.
 *
 * - v2_plan.prices_krw: a SEPARATE nullable JSON column, parallel to the
 *   existing `prices` column (which stays exactly as-is and keeps meaning
 *   CNY for every period key, legacy and days_*). Chosen over nesting
 *   {cny,krw} inside `prices` itself specifically because OrderController::
 *   save() does `optional($plan->prices)[$newPeriod] * 100` — multiplying
 *   a plain number. Nesting would turn that into multiplying an array,
 *   breaking every existing/legacy-period purchase silently. A parallel
 *   column keeps `prices`'s existing type contract 100% intact.
 * - v2_order.currency: nullable, defaults to NULL for existing rows (never
 *   backfilled/assumed) so pre-migration orders are visibly distinguishable
 *   from orders created after currency-aware checkout ships. New code that
 *   writes this column is NOT part of this migration — see the session
 *   report for why order/save's currency-aware total_amount computation
 *   was deliberately left unimplemented this round.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->json('prices_krw')->nullable()->after('prices')
                ->comment('ManduVPN: KRW price per period key, independent fixed sale price (NOT derived from prices/CNY at read time)');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->string('currency', 8)->nullable()->after('total_amount')
                ->comment('ManduVPN: snapshot of the currency this order was priced in (CNY/KRW). NULL for orders created before this column existed.');
        });
    }

    public function down(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('prices_krw');
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
