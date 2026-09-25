<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN coupon phase: currency-aware coupons. Additive only; existing rows keep NULL/defaults
// (fixed coupon with currency NULL = legacy CNY fen).
return new class extends Migration {
    public function up(): void
    {
        $has = fn (string $c) => Schema::hasColumn('v2_coupon', $c);
        Schema::table('v2_coupon', function (Blueprint $table) use ($has) {
            if (!$has('currency')) $table->string('currency', 8)->nullable();
            if (!$has('new_user_only')) $table->boolean('new_user_only')->default(false);
            if (!$has('min_amount')) $table->integer('min_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_coupon', function (Blueprint $table) {
            $table->dropColumn(['currency', 'new_user_only', 'min_amount']);
        });
    }
};
