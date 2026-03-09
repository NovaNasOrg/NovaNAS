<?php

use App\Models\DynDnsConfig;
use App\Models\UpnpRule;
use App\Services\Storage\SmartService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * DynDNS Scheduled Updates
 *
 * Each enabled DynDNS config is scheduled to update based on its interval_minutes setting.
 * The scheduler runs every minute and checks if any configs need updating.
 */
Schedule::call(function () {
    $configs = DynDnsConfig::enabled()->get();

    foreach ($configs as $config) {
        // Skip if never updated or if enough time has passed since last update
        if (!$config->last_updated_at) {
            // First time update - run synchronously
            dispatch_sync(new \App\Jobs\DynDnsUpdateJob($config->id));
            continue;
        }

        $nextUpdate = $config->last_updated_at->addMinutes($config->interval_minutes);

        if (now()->gte($nextUpdate)) {
            // Run synchronously within the schedule
            dispatch_sync(new \App\Jobs\DynDnsUpdateJob($config->id));
        }
    }
})->everyMinute()->name('dyndns-schedule-check');

/**
 * UPNP Port Mapping Renewal
 *
 * Renews UPNP port mappings every 30 minutes to keep them active.
 * UPNP mappings are temporary leases that need periodic renewal.
 */
Schedule::call(function () {
    $rules = UpnpRule::enabled()->get();

    foreach ($rules as $rule) {
        // Skip if never renewed or if enough time has passed since last renewal
        if (!$rule->last_renewed_at) {
            // First time publish - run synchronously
            dispatch_sync(new \App\Jobs\UpnpRenewJob($rule->id));
            continue;
        }

        $nextRenewal = $rule->last_renewed_at->addMinutes(30);

        if (now()->gte($nextRenewal)) {
            // Run synchronously within the schedule
            dispatch_sync(new \App\Jobs\UpnpRenewJob($rule->id));
        }
    }
})->everyMinute()->name('upnp-renewal-check');

/**
 * SMART Disk Tests
 *
 * Runs weekly SMART short tests on all non-system disks every Sunday at 2:00 AM.
 */
Schedule::call(function () {
    $smartService = new SmartService();
    $smartService->runTestsOnAllDisks('short');
})->weekly()->at('02:00')->name('smart-weekly-test');
