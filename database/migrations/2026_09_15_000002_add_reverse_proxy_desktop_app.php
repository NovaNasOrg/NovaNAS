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
            'identifier' => 'reverse-proxy',
            'name' => 'Reverse Proxy',
            'description' => 'Manage Apache reverse proxy hosts',
            'type' => 'component',
            'icon_type' => 'tabler',
            'icon_name' => 'IconTransfer',
            'color' => '#2dd4bf',
            'component_path' => 'ReverseProxyApp',
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
        DesktopApp::where('identifier', 'reverse-proxy')->delete();
    }
};
