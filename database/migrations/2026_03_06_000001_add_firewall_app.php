<?php

use App\Models\DesktopApp;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DesktopApp::create([
            'identifier' => 'firewall',
            'name' => 'Firewall',
            'description' => 'Manage UFW firewall rules and settings',
            'type' => 'component',
            'icon_type' => 'tabler',
            'icon_name' => 'IconShield',
            'color' => '#ef4444',
            'component_path' => 'FirewallApp',
            'is_system' => true,
            'is_global' => true,
            'is_admin_only' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DesktopApp::where('identifier', 'firewall')->delete();
    }
};
