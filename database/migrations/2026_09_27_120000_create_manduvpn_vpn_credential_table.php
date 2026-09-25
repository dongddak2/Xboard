<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN PP-C4-1A: independent IKEv2 VPN credential (not email / website password / uuid).
// One row per user (user_id is the key); username globally unique; only the encrypted secret is stored.
// Revocation deletes the row (no status column). Integer unix timestamps like the other v2_* tables.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_vpn_credential')) {
            Schema::create('v2_vpn_credential', function (Blueprint $table) {
                $table->integer('user_id')->primary();
                $table->string('username', 32)->unique();
                $table->text('secret_enc');
                $table->integer('rotated_at');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_vpn_credential');
    }
};
