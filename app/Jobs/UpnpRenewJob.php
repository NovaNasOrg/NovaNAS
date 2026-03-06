<?php

namespace App\Jobs;

use App\Models\UpnpRule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to renew a UPNP port mapping.
 */
class UpnpRenewJob implements ShouldQueue
{
    use Queueable;

    /**
     * The ID of the UPNP rule to renew.
     */
    protected int $ruleId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $ruleId)
    {
        $this->ruleId = $ruleId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $rule = UpnpRule::find($this->ruleId);

        if (!$rule) {
            Log::warning("UpnpRenewJob: Rule ID {$this->ruleId} not found");

            return;
        }

        if (!$rule->is_enabled) {
            Log::info("UpnpRenewJob: Rule {$rule->name} is disabled, skipping");

            return;
        }

        Log::info("UpnpRenewJob: Renewing {$rule->name} ({$rule->external_port}/{$rule->protocol})");

        $result = $rule->publish();

        if ($result['success']) {
            Log::info("UpnpRenewJob: {$rule->name} renewed successfully");
        } else {
            Log::error("UpnpRenewJob: {$rule->name} failed: {$result['message']}");
        }
    }
}
