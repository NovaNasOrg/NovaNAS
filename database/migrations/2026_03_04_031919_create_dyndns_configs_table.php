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
        Schema::create('dyndns_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('name');
            $table->string('subdomain');
            $table->text('token');
            $table->integer('interval_minutes')->default(5);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_updated_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dyndns_configs');
    }
};
