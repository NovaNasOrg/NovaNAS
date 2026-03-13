<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the default invitation lifetime setting (48 hours)
        DB::table('settings')->insert([
            'key' => 'users.invitation_lifetime_hours',
            'value' => '48',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'users.invitation_lifetime_hours')->delete();
    }
};
