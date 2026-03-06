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
        Schema::create('upnp_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('interface');
            $table->unsignedInteger('external_port');
            $table->unsignedInteger('internal_port');
            $table->enum('protocol', ['TCP', 'UDP'])->default('TCP');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('remote_host')->nullable()->default('');
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upnp_rules');
    }
};
