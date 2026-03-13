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
        Schema::table('users', function (Blueprint $table) {
            // Make username nullable (invited users may not have it yet)
            $table->string('username')->nullable()->change();

            // Invitation fields
            $table->uuid('invitation_token')->nullable()->unique()->after('username');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');

            // Status: 'pending' = invited but hasn't set password, 'active' = normal user
            $table->enum('status', ['pending', 'active'])->default('active')->after('invitation_expires_at');

            // Track when password was set
            $table->timestamp('password_set_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'invitation_token',
                'invitation_expires_at',
                'status',
                'password_set_at',
            ]);

            // Revert username to not nullable
            $table->string('username')->unique()->change();
        });
    }
};
