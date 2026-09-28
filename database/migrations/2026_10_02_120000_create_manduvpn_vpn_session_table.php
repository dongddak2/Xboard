<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN stage C: the server-issued VPN session the app must hold before it raises a tunnel
// (RC_CONTRACTS.md §23.19-a). Additive only - no existing table is touched. Integer unix timestamps like the
// other v2_* tables.
//
// THIS TABLE IS A RECORD OF ISSUANCE, NOT AN ENFORCEMENT POINT. Concurrent-connection and device limits stay
// where they already are: the node reports live IPs and UserOnlineService counts them (limiter.go compares
// deviceLimit <= aliveIp, i.e. it counts IPs). Counting rows here instead would create a SECOND definition of
// "how many devices are connected", and the two diverge under NAT and multi-IP clients. One definition only -
// this is a deliberate decision, not an omission.
//
// No unique constraint on (user_id, device_id): re-issuing a session for the same device is normal (app
// restart, reconnect, network change), and a unique key would make the second, legitimate request fail.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_vpn_session')) {
            Schema::create('v2_vpn_session', function (Blueprint $table) {
                $table->increments('id');
                // The identifier the app sends back on heartbeat / release. Opaque to the client.
                $table->string('session_id', 64)->unique();
                $table->integer('user_id');
                // Client-generated, so it is stored as given and never trusted as an identity.
                $table->string('device_id', 128);
                $table->integer('server_id');
                $table->string('protocol', 16);
                $table->string('routing_mode', 16);
                // ACTIVE | REVOKED | DISCONNECTED. The app only reads ACTIVE and REVOKED.
                $table->string('status', 16);
                $table->integer('connected_at');
                // Stays null until a heartbeat route exists. See the ceiling note in the controller.
                $table->integer('last_heartbeat_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index(['user_id', 'status'], 'v2_vpn_session_user_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_vpn_session');
    }
};
