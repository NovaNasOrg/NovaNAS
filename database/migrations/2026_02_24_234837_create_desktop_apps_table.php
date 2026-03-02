<?php

use App\Models\DesktopApp;
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
        Schema::create('desktop_apps', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['component', 'url'])->default('component');
            $table->string('url')->nullable();
            $table->enum('icon_type', ['tabler', 'image'])->default('tabler');
            $table->string('icon_name')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('color');
            $table->string('component_path')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_global')->default(false);
            $table->boolean('is_admin_only')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->seedDefaultApps();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('desktop_apps');
    }

    /**
     * Seed the default system desktop apps.
     */
    private function seedDefaultApps(): void
    {
        $apps = [
            [
                'identifier' => 'filemanager',
                'name' => 'File Manager',
                'description' => 'Browse and manage files on your NAS',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconFolder',
                'color' => '#eab308',
                'component_path' => 'FileManagerApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => false,
            ],
            [
                'identifier' => 'settings',
                'name' => 'Settings',
                'description' => 'System settings and configuration',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconSettings',
                'color' => '#64748b',
                'component_path' => 'SettingsApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => true,
            ],
            [
                'identifier' => 'terminal',
                'name' => 'Terminal',
                'description' => 'Access the command line interface',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconTerminal2',
                'color' => '#1e293b',
                'component_path' => 'TerminalApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => true,
            ],
            [
                'identifier' => 'docker',
                'name' => 'Docker',
                'description' => 'Manage Docker containers and images',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconBrandDocker',
                'color' => '#2496ED',
                'component_path' => 'DockerApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => true,
            ],
            [
                'identifier' => 'monitor',
                'name' => 'Monitor',
                'description' => 'System monitoring and resource usage',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconActivity',
                'color' => '#14b8a6',
                'component_path' => 'MonitorApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => true,
            ],
            [
                'identifier' => 'storage',
                'name' => 'Storage',
                'description' => 'Disk and storage management',
                'type' => 'component',
                'icon_type' => 'tabler',
                'icon_name' => 'IconDisc',
                'color' => '#f59e0b',
                'component_path' => 'StorageApp',
                'is_system' => true,
                'is_global' => true,
                'is_admin_only' => true,
            ],
        ];

        foreach ($apps as $app) {
            DesktopApp::create($app);
        }
    }
};
