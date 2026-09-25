<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN PP-C1: single-server entitlement for BASIC-tier plans. NULL = no single-server restriction.
// No foreign key yet (server deletion / reassignment policy is decided in a later phase). Existing rows stay NULL.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'assigned_server_id')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->integer('assigned_server_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'assigned_server_id')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('assigned_server_id');
            });
        }
    }
};
