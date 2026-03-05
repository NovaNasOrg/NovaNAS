<?php

namespace App\Jobs;

use App\Models\DynDnsConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to update a DynDNS configuration.
 */
class DynDnsUpdateJob implements ShouldQueue
{
    use Queueable;

    /**
     * The ID of the DynDNS configuration to update.
     */
    protected int $configId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $configId)
    {
        $this->configId = $configId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $config = DynDnsConfig::find($this->configId);

        if (!$config) {
            Log::warning("DynDnsUpdateJob: Config ID {$this->configId} not found");

            return;
        }

        if (!$config->is_enabled) {
            Log::info("DynDnsUpdateJob: Config {$config->name} is disabled, skipping");

            return;
        }

        Log::info("DynDnsUpdateJob: Updating {$config->name} ({$config->full_domain})");

        $result = $config->updateDns();

        if ($result['success']) {
            Log::info("DynDnsUpdateJob: {$config->name} updated successfully");
        } else {
            Log::error("DynDnsUpdateJob: {$config->name} failed: {$result['message']}");
        }
    }
}
