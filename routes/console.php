<?php

use App\Jobs\AutoDeleteTrashJob;
use App\Jobs\DynDnsUpdateJob;
use App\Jobs\RenewSelfSignedCertificatesJob;
use App\Jobs\UpnpRenewJob;
use App\Models\DynDnsConfig;
use App\Models\UpnpRule;
use App\Services\NovaNASUpdateService;
use App\Services\ProxyAuthService;
use App\Services\Storage\SmartService;
use App\Services\UpdateService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
        if (! $config->last_updated_at) {
            // First time update - run synchronously
            dispatch(new DynDnsUpdateJob($config->id));

            continue;
        }

        $nextUpdate = $config->last_updated_at->addMinutes($config->interval_minutes);

        if (now()->gte($nextUpdate)) {
            // Run synchronously within the schedule
            dispatch(new DynDnsUpdateJob($config->id));
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
        // It's to make sure the rule isn't added twice in a short period of time, which can happen if the job is delayed or if the system is restarted.
        if (! $rule->last_renewed_at) {
            // First time publish - run synchronously
            dispatch(new UpnpRenewJob($rule->id));

            continue;
        }

        $nextRenewal = $rule->last_renewed_at->addMinutes(30);

        if (now()->gte($nextRenewal)) {
            // Run synchronously within the schedule
            dispatch(new UpnpRenewJob($rule->id));
        }
    }
})->everyMinute()->name('upnp-renewal-check');

/**
 * SMART Disk Tests
 *
 * Runs weekly SMART short tests on all non-system disks every Sunday at 2:00 AM.
 */
Schedule::call(function () {
    $smartService = new SmartService;
    $smartService->runTestsOnAllDisks('short');
})->weekly()->at('02:00')->name('smart-weekly-test');

/**
 * System Updates
 *
 * Runs apt update and novanas update check every 2 hours to keep package lists current.
 */
Schedule::call(function () {
    $updateService = new UpdateService;
    $result = $updateService->startCheckForUpdates();
    $novaNasUpdateService = new NovaNASUpdateService;
    $result = $novaNasUpdateService->checkForUpdates();

    if ($result['success']) {
        Log::info('Scheduled updates check completed successfully');
    } else {
        Log::error('Scheduled updates check failed: '.$result['message']);
    }
})->everyTwoHours()->name('updates-check');

/**
 * Self-Signed Certificate Renewal
 *
 * Renews self-signed certificates that are expiring within a month.
 * Runs monthly to keep self-signed certificates current.
 */
Schedule::job(new RenewSelfSignedCertificatesJob)
    ->monthly()
    ->name('self-signed-cert-renewal');

/**
 * Auto-Delete Expired Trash
 *
 * Permanently deletes files that have exceeded their retention period in trash.
 * Runs daily at 3:00 AM.
 */
Schedule::job(new AutoDeleteTrashJob)
    ->dailyAt('03:00')
    ->name('auto-delete-trash');

/**
 * Log Auto-Deletion
 *
 * Removes log lines older than the configured retention period (default 30 days)
 * from every file in the logs directory. Runs daily at 4:00 AM.
 */
Schedule::command('logs:prune')
    ->dailyAt('04:00')
    ->name('logs-prune');

/**
 * Backup Scheduler
 *
 * Checks for due backup jobs every minute and launches them in tmux sessions.
 * Each job runs independently and concurrently via tmux.
 */
Schedule::command('backup:run-scheduled')
    ->everyMinute()
    ->name('backup-scheduler');

/**
 * Reverse Proxy Login Token Pruning
 *
 * Removes expired reverse proxy session tokens and tokens whose NAS session
 * no longer exists, then refreshes the Apache token maps. Runs hourly.
 */
Schedule::call(function () {
    app(ProxyAuthService::class)->pruneTokens();
})->hourly()->name('proxy-auth-token-prune');
