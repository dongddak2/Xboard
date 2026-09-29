<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN HY2-CRED-1 (RC_CONTRACTS §23.57, candidate B): the short-lived Hysteria2 credential.
//
// ADDITIVE ONLY. A new table - no column, index or constraint of any existing table is touched. In particular
// v2_vpn_credential (IKEv2) is NOT reused: its primary key is user_id with one row already owned by IKEv2, and
// it has no expiry column, so reuse would mean editing the IKEv2 table.
//
// UNIT = USER, deliberately, because the node's user list carries exactly one credential per user
// (ServerService::getAvailableUsers selects one uuid per row). A per-device or per-session unit would need the
// node to accept several values for one user, which is unmeasured on V2bX, so it is not built.
//
// EXPIRY IS AN ABSOLUTE INSTANT (expires_at, unix int), not a TTL stored beside issued_at: the reading side
// then does no arithmetic, the comparison is engine-neutral, and changing the TTL later cannot retroactively
// change what an already-issued row means.
//
// Only the ENCRYPTED secret is stored (secret_enc), the same shape as v2_vpn_credential. App\Models\Hy2Credential
// hides it from every serializer.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_vpn_hy2_credential')) {
            Schema::create('v2_vpn_hy2_credential', function (Blueprint $table) {
                $table->integer('user_id')->primary();
                $table->text('secret_enc');
                // Absolute unix instant. A row whose expires_at has passed is not served to any node and is
                // not handed to any client; it is replaced on the next read.
                $table->integer('expires_at');
                // When the CURRENT secret was created (extending expires_at does not change it), so a later
                // question of the form "how old is the live secret" has an answer.
                $table->integer('issued_at');
                $table->integer('created_at');
                $table->integer('updated_at');
                // The node pull reads "every live row for these user ids".
                $table->index(['expires_at'], 'v2_vpn_hy2_credential_expires_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_vpn_hy2_credential');
    }
};
