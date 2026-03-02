<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change integer positions to decimal percentages (0-100)
        Schema::table('user_desktop_icons', function (Blueprint $table) {
            $table->decimal('position_x', 5, 2)->default(0)->change();
            $table->decimal('position_y', 5, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_desktop_icons', function (Blueprint $table) {
            $table->integer('position_x')->default(0)->change();
            $table->integer('position_y')->default(0)->change();
        });
    }
};
