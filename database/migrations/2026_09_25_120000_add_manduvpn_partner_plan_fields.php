<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN PP-A: partner status on users, audience + explicit service tier on plans. Additive only;
// existing users stay is_partner=0 and existing plans stay audience='public', service_tier NULL.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'is_partner')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->boolean('is_partner')->default(false);
            });
        }
        $has = fn (string $c) => Schema::hasColumn('v2_plan', $c);
        Schema::table('v2_plan', function (Blueprint $table) use ($has) {
            if (!$has('audience')) $table->string('audience', 16)->default('public');
            if (!$has('service_tier')) $table->string('service_tier', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn(['audience', 'service_tier']);
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('is_partner');
        });
    }
};
