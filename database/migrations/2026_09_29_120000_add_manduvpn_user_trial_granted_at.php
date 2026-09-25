<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN TR-1b: one-time free-trial evidence. NULL = never received a trial (no backfill of existing users).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'trial_granted_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->integer('trial_granted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'trial_granted_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('trial_granted_at');
            });
        }
    }
};
