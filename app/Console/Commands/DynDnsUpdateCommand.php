<?php

namespace App\Console\Commands;

use App\Models\DynDnsConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to update DynDNS configurations.
 *
 * Can update a specific config by ID or all enabled configs.
 */
class DynDnsUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dyndns:update {--id= : The ID of the specific DynDNS config to update}
                            {--all : Update all enabled DynDNS configs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update DynDNS configurations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $configId = $this->option('id');
        $updateAll = $this->option('all');

        if ($configId) {
            return $this->updateSingle($configId);
        }

        if ($updateAll) {
            return $this->updateAll();
        }

        $this->error('Please specify either --id=<config-id> or --all');
        $this->info('Usage: php artisan dyndns:update --id=1');
        $this->info('       php artisan dyndns:update --all');

        return self::FAILURE;
    }

    /**
     * Update a single DynDNS configuration.
     */
    protected function updateSingle(int $id): int
    {
        $config = DynDnsConfig::find($id);

        if (!$config) {
            $this->error("DynDNS configuration with ID {$id} not found.");

            return self::FAILURE;
        }

        $this->info("Updating DynDNS configuration: {$config->name} ({$config->full_domain})");

        $result = $config->updateDns();

        if ($result['success']) {
            $this->info('✓ ' . $result['message']);

            return self::SUCCESS;
        }

        $this->error('✗ ' . $result['message']);

        return self::FAILURE;
    }

    /**
     * Update all enabled DynDNS configurations.
     */
    protected function updateAll(): int
    {
        $configs = DynDnsConfig::enabled()->get();

        if ($configs->isEmpty()) {
            $this->info('No enabled DynDNS configurations found.');

            return self::SUCCESS;
        }

        $this->info("Found {$configs->count()} enabled DynDNS configuration(s).");

        $successCount = 0;
        $failureCount = 0;

        foreach ($configs as $config) {
            $this->line("");
            $this->line("Updating: {$config->name} ({$config->full_domain})");

            $result = $config->updateDns();

            if ($result['success']) {
                $this->info("  ✓ {$result['message']}");
                $successCount++;
            } else {
                $this->error("  ✗ {$result['message']}");
                $failureCount++;
            }
        }

        $this->line("");
        $this->info("Update complete: {$successCount} success, {$failureCount} failure(s).");

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
