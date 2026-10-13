<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2UserContactTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_user_contact')) {
            if (!Schema::hasColumns('v2_user_contact', ['user_id', 'real_name', 'wechat_id', 'phone_country_code', 'phone_number', 'consent_version', 'consented_at', 'created_at'])) {
                throw new \RuntimeException('Existing contact table does not match the required schema. Preserve data and repair the schema before retrying.');
            }
            return;
        }
        Schema::create('v2_user_contact', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('real_name', 80);
            $table->string('wechat_id', 64);
            $table->string('phone_country_code', 4);
            $table->string('phone_number', 15);
            $table->string('consent_version', 32);
            $table->unsignedBigInteger('consented_at');
            $table->unsignedBigInteger('created_at');
        });
    }

    public function down()
    {
        // Retain contact data on code rollback. No automatic deletion of personal records.
    }
}
