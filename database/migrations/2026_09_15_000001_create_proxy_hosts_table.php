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
        Schema::create('proxy_hosts', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('target_host');
            $table->unsignedSmallInteger('target_port');
            $table->string('target_protocol', 10)->default('http'); // http, https
            $table->boolean('websocket_enabled')->default(false);
            $table->boolean('https_redirect')->default(false);
            $table->string('ssl_mode', 20)->default('none'); // none, letsencrypt, selfsigned, custom
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_hosts');
    }
};
