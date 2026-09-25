<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ManduVPN: which currency a given Payment gateway charges in becomes
 * server-stored data instead of something inferred from the gateway class
 * name/string at request time. Defaults every existing row to 'CNY' (the
 * currency every pre-existing gateway — Alipay/WeChat/etc. — already
 * assumed via the single global admin_setting('currency')), so this is a
 * pure backfill with zero behavior change for anything that isn't
 * TossPayments.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_payment', function (Blueprint $table) {
            $table->string('currency', 8)->default('CNY')->after('payment')
                ->comment('ManduVPN: fixed sale currency for this gateway (CNY/KRW) — server-authoritative, never inferred from the client.');
        });

        DB::table('v2_payment')->whereNull('currency')->update(['currency' => 'CNY']);
        DB::table('v2_payment')->where('payment', 'TossPayments')->update(['currency' => 'KRW']);
    }

    public function down(): void
    {
        Schema::table('v2_payment', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
