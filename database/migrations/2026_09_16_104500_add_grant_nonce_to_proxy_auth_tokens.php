<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proxy_auth_tokens', function (Blueprint $table) {
            $table->string('grant_nonce', 64)->nullable()->unique();
            $table->dateTime('grant_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxy_auth_tokens', function (Blueprint $table) {
            $table->dropColumn(['grant_nonce', 'grant_expires_at']);
        });
    }
};
