<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_desktop_icons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('desktop_app_id')->constrained()->onDelete('cascade');
            // Store positions as percentages (0-100) of screen size
            $table->decimal('position_x', 5, 2)->default(0);
            $table->decimal('position_y', 5, 2)->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'desktop_app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_desktop_icons');
    }
};
