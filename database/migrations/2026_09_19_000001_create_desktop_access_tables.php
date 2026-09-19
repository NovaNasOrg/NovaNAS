<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('platform', 32);
            $table->string('samba_username', 20)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->unique(['user_id', 'uuid']);
        });

        Schema::create('desktop_shared_folders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('samba_name', 80)->unique();
            $table->string('path', 4096)->nullable();
            $table->string('type', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_shared_folders');
        Schema::dropIfExists('desktop_devices');
    }
};
