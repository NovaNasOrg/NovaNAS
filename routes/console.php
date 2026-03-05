<?php

use App\Models\DynDnsConfig;
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
            // First time update
            dispatch(new \App\Jobs\DynDnsUpdateJob($config->id));
            continue;
        }

        $nextUpdate = $config->last_updated_at->addMinutes($config->interval_minutes);

        if (now()->gte($nextUpdate)) {
            dispatch(new \App\Jobs\DynDnsUpdateJob($config->id));
        }
    }
})->everyMinute()->name('dyndns-schedule-check');
