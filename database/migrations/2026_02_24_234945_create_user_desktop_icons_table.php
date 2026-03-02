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
            // Use simple order (1, 2, 3, 4...) instead of grid positions
            $table->integer('order')->default(0);
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
