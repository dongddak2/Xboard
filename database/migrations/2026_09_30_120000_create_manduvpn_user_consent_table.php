<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ManduVPN RC: evidence of the three mandatory signup consents (RC_CONTRACTS.md §3.2).
// Additive only - no existing table is touched. Integer unix timestamps like the other v2_* tables.
// One row per (user, consent version): a new version records a NEW row so an older consent stays
// distinguishable and is never overwritten. UNIQUE(user_id, consent_version) makes that the DB's job.
// Deliberately stores no IP, name or any other personal data beyond the user reference.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_consent')) {
            Schema::create('v2_user_consent', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('consent_version', 64);
                $table->string('locale', 8);
                $table->boolean('agreed_identity');
                $table->boolean('agreed_account_sharing');
                $table->boolean('agreed_payment_refund');
                $table->integer('agreed_at');
                $table->integer('created_at');
                $table->unique(['user_id', 'consent_version'], 'v2_user_consent_user_version_unique');
                $table->index('consent_version');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_consent');
    }
};
