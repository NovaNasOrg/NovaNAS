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
        Schema::table('proxy_hosts', function (Blueprint $table) {
            $table->boolean('auth_enabled')->default(false);
        });

        Schema::create('proxy_host_auth_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_host_id')->constrained('proxy_hosts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['proxy_host_id', 'user_id']);
        });

        Schema::create('proxy_auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('session_id')->nullable()->index();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_auth_tokens');
        Schema::dropIfExists('proxy_host_auth_users');
        Schema::table('proxy_hosts', function (Blueprint $table) {
            $table->dropColumn('auth_enabled');
        });
    }
};
