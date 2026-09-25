<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN PP-C4-1B: IKEv2 node identity (1:1 with v2_server via server_id), per-node token hashes and health metadata.
// Only sha256 hashes of tokens are stored. The lifecycle state is derived (IkeV2NodeService::state), not stored.
// Integer unix timestamps and no FK constraints, like the other v2_* tables.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_ikev2_node')) {
            Schema::create('v2_ikev2_node', function (Blueprint $table) {
                $table->integer('server_id')->primary();
                $table->char('token_hash', 64)->nullable();
                $table->char('token_prev_hash', 64)->nullable();
                $table->integer('token_prev_expires_at')->nullable();
                $table->integer('first_seen_at')->nullable();
                $table->integer('last_seen_at')->nullable();
                $table->string('applied_revision', 64)->nullable();
                $table->string('agent_version', 64)->nullable();
                $table->string('strongswan_version', 64)->nullable();
                $table->string('last_error', 255)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ikev2_node');
    }
};
