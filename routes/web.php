<?php

use App\Http\Controllers\ApplicationsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupJobController;
use App\Http\Controllers\BackupRepositoryController;
use App\Http\Controllers\BackupServerController;
use App\Http\Controllers\BackupSnapshotController;
use App\Http\Controllers\DesktopAccessController;
use App\Http\Controllers\DesktopIconController;
use App\Http\Controllers\DockerComposeController;
use App\Http\Controllers\DockerController;
use App\Http\Controllers\DockerSettingsController;
use App\Http\Controllers\DynDnsController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FileManagerSettingsController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\GPUController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogSettingsController;
use App\Http\Controllers\LogViewerController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\ProxyAuthController;
use App\Http\Controllers\ReverseProxyController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\SmartController;
use App\Http\Controllers\SslSettingsController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UpnpController;
use App\Http\Controllers\UpsSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WizardController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/up', fn () => response()->json([
    'system' => 'NovaNAS',
    'version' => config('app.version'),
]));

// Wizard routes (accessible without authentication when no users exist)
Route::get('/wizard', [WizardController::class, 'index']);
Route::get('/wizard/account', [WizardController::class, 'account']);
Route::post('/wizard/account', [WizardController::class, 'storeAccount']);
Route::get('/wizard/bind-user', [WizardController::class, 'bindUser']);
Route::post('/wizard/bind-user', [WizardController::class, 'storeBindUser']);
Route::get('/wizard/skip', [WizardController::class, 'skip']);

Route::get('/login', [AuthController::class, 'login'])->name('login');

Route::post('/login', [AuthController::class, 'authenticate']);

Route::post('/login/2fa', [AuthController::class, 'verifyTwoFactor'])->name('login.2fa');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Password set route for invited users (no auth required)
Route::get('/set-password', [UserController::class, 'showSetPassword']);
Route::post('/set-password', [UserController::class, 'setPassword']);

// Invitation route (clean URL structure)
Route::get('/invitation/{token}', [UserController::class, 'showSetPassword']);

// Reverse proxy login bridge (public - runs on the NAS domain and on proxy domains)
Route::get('/proxy-auth/login', [ProxyAuthController::class, 'login'])->name('proxy-auth.login');
Route::get('/__novanas_proxy_auth/grant', [ProxyAuthController::class, 'grant'])->name('proxy-auth.grant');

Route::group(['middleware' => 'auth'], function () {
    Route::get('/', [HomeController::class, 'index']);

    // Desktop app authentication handoff landing page
    Route::get('/desktop/success', function () {
        return response('<!DOCTYPE html><html><head><meta charset="utf-8"><title>NovaNAS Desktop</title><style>body{background:#0d0f12;color:#fff;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;}.card{background:#16161c;border:1px solid #282b36;padding:40px;border-radius:16px;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,0.5);}.icon{width:56px;height:56px;background:rgba(32,153,240,0.15);color:#2099f0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;}h1{font-size:20px;margin:0 0 8px;}p{color:#8c93a8;font-size:14px;line-height:1.5;margin:0;}</style></head><body><div class="card"><div class="icon">&#10003;</div><h1>Connected to Desktop</h1><p>You have successfully logged in. You can now return to the NovaNAS desktop app.</p></div></body></html>');
    });

    Route::withoutMiddleware(HandleInertiaRequests::class)->group(function () {
        // Desktop apps API (for live refresh)
        Route::get('/api/desktop-apps', [HomeController::class, 'desktopApps']);

        Route::get('/api/system/info', [SystemController::class, 'info']);
        Route::get('/api/desktop/folders', [DesktopAccessController::class, 'index']);
        Route::post('/api/desktop/devices', [DesktopAccessController::class, 'store']);
        Route::delete('/api/desktop/devices/{uuid}', [DesktopAccessController::class, 'destroy']);
        Route::get('/api/system/network-interfaces', [SystemController::class, 'networkInterfaces']);
        Route::get('/api/system/network-config', [SystemController::class, 'getNetworkConfig']);
        Route::get('/api/system/interface-config/{interface}', [SystemController::class, 'getInterfaceConfig']);
        Route::post('/api/system/network-config', [SystemController::class, 'setNetworkConfig']);
        Route::get('/api/storage/directories', [SystemController::class, 'listDirectory']);
        Route::post('/api/storage/directories', [SystemController::class, 'createDirectory']);

        // Network controller routes
        Route::get('/api/network/interfaces', [NetworkController::class, 'index']);
        Route::get('/api/network/config/{interface}', [NetworkController::class, 'getConfig']);
        Route::post('/api/network/config', [NetworkController::class, 'setConfig']);

        // Desktop icon routes - order based (simple 1, 2, 3, 4...)
        Route::put('/api/desktop-icons/order', [DesktopIconController::class, 'updateOrder']);
        Route::put('/api/desktop-icons/visibility', [DesktopIconController::class, 'toggleVisibility']);
        Route::get('/api/desktop-icons/orders', [DesktopIconController::class, 'orders']);

        // DynDNS routes
        Route::get('/api/dyndns/configs', [DynDnsController::class, 'index']);
        Route::get('/api/dyndns/info', [DynDnsController::class, 'getInfo']);
        Route::post('/api/dyndns/configs', [DynDnsController::class, 'store']);
        Route::put('/api/dyndns/configs/{id}', [DynDnsController::class, 'update']);
        Route::delete('/api/dyndns/configs/{id}', [DynDnsController::class, 'destroy']);
        Route::post('/api/dyndns/configs/{id}/update', [DynDnsController::class, 'updateNow']);
        Route::post('/api/dyndns/update-all', [DynDnsController::class, 'updateAll']);
        Route::get('/api/dyndns/provider-fields', [DynDnsController::class, 'getProviderFields']);
        Route::post('/api/dyndns/configs/{id}/set-hostname', [DynDnsController::class, 'setHostname']);

        // UPNP routes
        Route::get('/api/upnp/rules', [UpnpController::class, 'index']);
        Route::post('/api/upnp/rules', [UpnpController::class, 'store']);
        Route::put('/api/upnp/rules/{id}', [UpnpController::class, 'update']);
        Route::delete('/api/upnp/rules/{id}', [UpnpController::class, 'destroy']);
        Route::post('/api/upnp/publish-all', [UpnpController::class, 'publishAll']);
        Route::get('/api/upnp/discover', [UpnpController::class, 'discover']);
        Route::get('/api/upnp/interfaces', [UpnpController::class, 'getInterfaces']);

        // SSL settings routes
        Route::get('/api/settings/ssl', [SslSettingsController::class, 'index']);
        Route::post('/api/settings/ssl/check-reachability', [SslSettingsController::class, 'checkReachability']);
        Route::post('/api/settings/ssl/issue-certificate', [SslSettingsController::class, 'issueCertificate']);
        Route::post('/api/settings/ssl/install-certificate', [SslSettingsController::class, 'installCertificate']);
        Route::post('/api/settings/ssl/enable', [SslSettingsController::class, 'enableSsl']);
        Route::post('/api/settings/ssl/disable', [SslSettingsController::class, 'disableSsl']);
        Route::get('/api/settings/ssl/firewall-port', [SslSettingsController::class, 'checkFirewallPort']);
        Route::delete('/api/settings/ssl/certificate', [SslSettingsController::class, 'deleteCertificate']);
        Route::post('/api/settings/ssl/generate-self-signed', [SslSettingsController::class, 'generateSelfSigned']);

        // Firewall routes
        Route::get('/api/firewall/status', [FirewallController::class, 'status']);
        Route::get('/api/firewall/rules', [FirewallController::class, 'rules']);
        Route::get('/api/firewall/default-policies', [FirewallController::class, 'defaultPolicies']);
        Route::post('/api/firewall/enable', [FirewallController::class, 'enable']);
        Route::post('/api/firewall/disable', [FirewallController::class, 'disable']);
        Route::post('/api/firewall/rules', [FirewallController::class, 'store']);
        Route::put('/api/firewall/rules/reorder', [FirewallController::class, 'reorder']);
        Route::put('/api/firewall/rules/{id}', [FirewallController::class, 'update']);
        Route::delete('/api/firewall/rules/{id}', [FirewallController::class, 'destroy']);
        Route::put('/api/firewall/default-policies', [FirewallController::class, 'setDefaultPolicy']);
        Route::get('/api/firewall/interfaces', [FirewallController::class, 'interfaces']);

        // Reverse proxy routes
        Route::get('/api/reverse-proxy/hosts', [ReverseProxyController::class, 'index']);
        Route::post('/api/reverse-proxy/hosts', [ReverseProxyController::class, 'store']);
        Route::post('/api/reverse-proxy/check-reachability', [ReverseProxyController::class, 'checkReachability']);
        Route::put('/api/reverse-proxy/hosts/{host}', [ReverseProxyController::class, 'update']);
        Route::delete('/api/reverse-proxy/hosts/{host}', [ReverseProxyController::class, 'destroy']);
        Route::post('/api/reverse-proxy/hosts/{host}/toggle', [ReverseProxyController::class, 'toggle']);
        Route::post('/api/reverse-proxy/hosts/{host}/ssl/letsencrypt', [ReverseProxyController::class, 'issueLetsEncrypt']);
        Route::post('/api/reverse-proxy/hosts/{host}/ssl/self-signed', [ReverseProxyController::class, 'generateSelfSigned']);
        Route::post('/api/reverse-proxy/hosts/{host}/ssl/custom', [ReverseProxyController::class, 'installCustomCertificate']);
        Route::delete('/api/reverse-proxy/hosts/{host}/ssl', [ReverseProxyController::class, 'removeCertificate']);

        // Storage routes
        Route::get('/api/storage/disks', [StorageController::class, 'disks']);
        Route::get('/api/storage/disks/{device}/capacity', [StorageController::class, 'capacity']);
        Route::get('/api/storage/backends', [StorageController::class, 'backends']);
        Route::post('/api/storage/pools/validate-name', [StorageController::class, 'validatePoolName']);
        Route::post('/api/storage/pools', [StorageController::class, 'createPool']);
        Route::get('/api/storage/pools', [StorageController::class, 'pools']);
        Route::get('/api/storage/pools/{pool}', [StorageController::class, 'pool']);
        Route::get('/api/storage/pools/{pool}/directories', [StorageController::class, 'poolDirectories']);
        Route::post('/api/storage/pools/unmount', [StorageController::class, 'unmountPool']);
        Route::post('/api/storage/pools/mount', [StorageController::class, 'mountPool']);
        Route::delete('/api/storage/pools/delete', [StorageController::class, 'deletePool']);
        Route::get('/api/storage/settings', [StorageController::class, 'getSettings']);
        Route::post('/api/storage/settings', [StorageController::class, 'updateSettings']);

        // SMART routes
        Route::get('/api/storage/smart/health', [SmartController::class, 'health']);
        Route::get('/api/storage/smart/{device}/health', [SmartController::class, 'healthStatus']);
        Route::get('/api/storage/smart/{device}/tests', [SmartController::class, 'testResults']);
        Route::get('/api/storage/smart/{device}/info', [SmartController::class, 'detailedInfo']);
        Route::post('/api/storage/smart/{device}/test', [SmartController::class, 'startTest']);
        Route::post('/api/storage/smart/scan-all', [SmartController::class, 'scanAll']);

        // Shares routes
        Route::get('/api/storage/shares', [StorageController::class, 'shares']);
        Route::post('/api/storage/shares', [StorageController::class, 'createShare']);
        Route::put('/api/storage/shares/{name}', [StorageController::class, 'updateShare']);
        Route::delete('/api/storage/shares/{name}', [StorageController::class, 'deleteShare']);
        Route::get('/api/storage/shares/users', [StorageController::class, 'shareUsers']);
        Route::post('/api/storage/shares/homes', [StorageController::class, 'toggleHomes']);

        // File Manager settings routes
        Route::get('/api/settings/filemanager', [FileManagerSettingsController::class, 'index']);
        Route::put('/api/settings/filemanager', [FileManagerSettingsController::class, 'update']);

        // Log auto-deletion settings routes
        Route::get('/api/settings/logs', [LogSettingsController::class, 'index']);
        Route::put('/api/settings/logs', [LogSettingsController::class, 'update']);
        Route::post('/api/settings/logs/prune', [LogSettingsController::class, 'prune']);

        // Log viewer routes
        Route::get('/api/logs/files', [LogViewerController::class, 'files']);
        Route::get('/api/logs/view', [LogViewerController::class, 'view']);
        Route::get('/api/logs/search', [LogViewerController::class, 'search']);

        // File Manager routes
        Route::get('/api/filemanager/layout', [FileManagerController::class, 'getLayout']);
        Route::put('/api/filemanager/layout', [FileManagerController::class, 'updateLayout']);
        Route::get('/api/filemanager/hidden-files', [FileManagerController::class, 'getHiddenFiles']);
        Route::put('/api/filemanager/hidden-files', [FileManagerController::class, 'updateHiddenFiles']);
        Route::get('/api/filemanager/shares', [FileManagerController::class, 'shares']);
        Route::get('/api/filemanager/files', [FileManagerController::class, 'files']);
        Route::post('/api/filemanager/directories', [FileManagerController::class, 'createDirectory']);
        Route::delete('/api/filemanager/delete', [FileManagerController::class, 'delete']);
        Route::post('/api/filemanager/copy', [FileManagerController::class, 'copy']);
        Route::post('/api/filemanager/move', [FileManagerController::class, 'move']);
        Route::post('/api/filemanager/zip', [FileManagerController::class, 'zip']);
        Route::post('/api/filemanager/unzip', [FileManagerController::class, 'unzip']);
        Route::get('/api/filemanager/download', [FileManagerController::class, 'download']);
        Route::post('/api/filemanager/upload', [FileManagerController::class, 'upload']);

        // File Manager trash routes
        Route::get('/api/filemanager/trash', [FileManagerController::class, 'getTrash']);
        Route::post('/api/filemanager/trash/restore', [FileManagerController::class, 'restore']);
        Route::delete('/api/filemanager/trash/force-delete', [FileManagerController::class, 'forceDelete']);
        Route::delete('/api/filemanager/trash/empty', [FileManagerController::class, 'emptyTrash']);

        // User management routes
        Route::get('/api/users', [UserController::class, 'index']);
        Route::post('/api/users', [UserController::class, 'store']);
        Route::put('/api/users/{user}', [UserController::class, 'update']);
        Route::delete('/api/users/{user}', [UserController::class, 'destroy']);

        // Current user profile route
        Route::get('/api/profile', [UserController::class, 'showProfile']);
        Route::put('/api/profile', [UserController::class, 'profile']);

        // Passkey management routes
        Route::get('/api/passkeys', [PasskeyController::class, 'index']);
        Route::post('/api/passkeys', [PasskeyController::class, 'store']);
        Route::get('/api/passkeys/generate-options', [PasskeyController::class, 'generateOptions']);
        Route::delete('/api/passkeys/{id}', [PasskeyController::class, 'destroy']);

        // Two-factor authentication routes
        Route::get('/api/2fa', [TwoFactorController::class, 'show']);
        Route::post('/api/2fa', [TwoFactorController::class, 'store']);
        Route::post('/api/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('/api/2fa', [TwoFactorController::class, 'destroy']);

        // User invitation routes
        Route::get('/api/users/pending', [UserController::class, 'pending']);
        Route::post('/api/users/invite', [UserController::class, 'invite']);
        Route::post('/api/users/invitations/{user}/send-email', [UserController::class, 'sendInvitationEmail']);
        Route::delete('/api/users/invitations/{user}', [UserController::class, 'revokeInvitation']);

        // SMTP status check
        Route::get('/api/email/status', [UserController::class, 'smtpStatus']);

        // Available Linux users for linking
        Route::get('/api/users/linux/available', [UserController::class, 'availableLinuxUsers']);

        // Email/SMTP settings routes
        Route::get('/api/email/settings', [EmailSettingsController::class, 'index']);
        Route::post('/api/email/settings', [EmailSettingsController::class, 'store']);
        Route::post('/api/email/test', [EmailSettingsController::class, 'test']);

        // General settings routes
        Route::get('/api/settings/general', [GeneralSettingsController::class, 'index']);
        Route::put('/api/settings/general', [GeneralSettingsController::class, 'update']);

        // UPS settings routes
        Route::get('/api/settings/ups', [UpsSettingsController::class, 'index']);
        Route::get('/api/settings/ups/detect', [UpsSettingsController::class, 'detect']);
        Route::get('/api/settings/ups/status', [UpsSettingsController::class, 'status']);
        Route::put('/api/settings/ups', [UpsSettingsController::class, 'update']);
        Route::post('/api/settings/ups/apply', [UpsSettingsController::class, 'apply']);

        // Docker settings routes
        Route::get('/api/settings/docker', [DockerSettingsController::class, 'index']);
        Route::get('/api/settings/docker/auto-update', [DockerSettingsController::class, 'getAutoUpdate']);
        Route::put('/api/settings/docker/auto-update', [DockerSettingsController::class, 'updateAutoUpdate']);

        // Docker API routes
        Route::get('/api/docker/ping', [DockerController::class, 'ping']);
        Route::get('/api/docker/info', [DockerController::class, 'info']);
        Route::get('/api/docker/version', [DockerController::class, 'version']);

        // Containers
        Route::get('/api/docker/containers', [DockerController::class, 'containers']);
        Route::get('/api/docker/containers/{id}', [DockerController::class, 'container']);
        Route::post('/api/docker/containers/{id}/start', [DockerController::class, 'startContainer']);
        Route::post('/api/docker/containers/{id}/stop', [DockerController::class, 'stopContainer']);
        Route::post('/api/docker/containers/{id}/restart', [DockerController::class, 'restartContainer']);
        Route::delete('/api/docker/containers/{id}', [DockerController::class, 'removeContainer']);
        Route::get('/api/docker/containers/{id}/logs', [DockerController::class, 'containerLogs']);
        Route::get('/api/docker/containers/{id}/stats', [DockerController::class, 'containerStats']);
        Route::post('/api/docker/containers', [DockerController::class, 'createContainer']);
        Route::get('/api/docker/containers/{id}/config', [DockerController::class, 'getContainerConfig']);
        Route::post('/api/docker/containers/{id}/recreate', [DockerController::class, 'recreateContainer']);

        // Images
        Route::get('/api/docker/images', [DockerController::class, 'images']);
        Route::get('/api/docker/images/{id}', [DockerController::class, 'image']);
        Route::post('/api/docker/images/pull', [DockerController::class, 'pull']);
        Route::delete('/api/docker/images/{id}', [DockerController::class, 'removeImage']);

        // Volumes
        Route::get('/api/docker/volumes', [DockerController::class, 'volumes']);
        Route::get('/api/docker/volumes/{name}', [DockerController::class, 'volume']);
        Route::post('/api/docker/volumes', [DockerController::class, 'createVolume']);
        Route::delete('/api/docker/volumes/{name}', [DockerController::class, 'removeVolume']);

        // Networks
        Route::get('/api/docker/networks', [DockerController::class, 'networks']);
        Route::get('/api/docker/networks/{id}', [DockerController::class, 'network']);
        Route::post('/api/docker/networks', [DockerController::class, 'createNetwork']);
        Route::delete('/api/docker/networks/{id}', [DockerController::class, 'removeNetwork']);
        Route::post('/api/docker/networks/{id}/connect', [DockerController::class, 'connectNetwork']);
        Route::post('/api/docker/networks/{id}/disconnect', [DockerController::class, 'disconnectNetwork']);

        // Prune
        Route::post('/api/docker/prune/containers', [DockerController::class, 'pruneContainers']);
        Route::post('/api/docker/prune/images', [DockerController::class, 'pruneImages']);
        Route::post('/api/docker/prune/volumes', [DockerController::class, 'pruneVolumes']);
        Route::post('/api/docker/prune/networks', [DockerController::class, 'pruneNetworks']);

        // Registries
        Route::get('/api/docker/registries', [DockerController::class, 'listRegistries']);
        Route::post('/api/docker/registries', [DockerController::class, 'addRegistry']);
        Route::post('/api/docker/registries/{address}/login', [DockerController::class, 'loginToRegistry']);
        Route::post('/api/docker/registries/{address}/logout', [DockerController::class, 'logoutFromRegistry']);
        Route::delete('/api/docker/registries/{address}', [DockerController::class, 'removeRegistry']);

        // Docker Compose Projects
        Route::get('/api/docker/projects', [DockerComposeController::class, 'index']);
        Route::get('/api/docker/projects/{name}', [DockerComposeController::class, 'show']);
        Route::post('/api/docker/projects', [DockerComposeController::class, 'store']);
        Route::put('/api/docker/projects/{name}', [DockerComposeController::class, 'update']);
        Route::delete('/api/docker/projects/{name}', [DockerComposeController::class, 'destroy']);
        Route::post('/api/docker/projects/{name}/start', [DockerComposeController::class, 'start']);
        Route::post('/api/docker/projects/{name}/stop', [DockerComposeController::class, 'stop']);
        Route::post('/api/docker/projects/{name}/restart', [DockerComposeController::class, 'restart']);
        Route::get('/api/docker/projects/{name}/logs', [DockerComposeController::class, 'logs']);

        // Services routes
        Route::get('/api/services', [ServicesController::class, 'index']);
        Route::post('/api/services/toggle', [ServicesController::class, 'toggle']);

        // GPU routes
        Route::get('/api/gpus', [GPUController::class, 'index']);
        Route::get('/api/gpus/status', [GPUController::class, 'status']);
        Route::get('/api/gpus/providers', [GPUController::class, 'providers']);
        Route::get('/api/gpus/{provider}/{index}', [GPUController::class, 'show']);

        // Badge routes
        Route::get('/api/badges', [HomeController::class, 'badges']);

        // Terminal routes
        Route::post('/api/terminal/session', [TerminalController::class, 'createSession']);
        Route::delete('/api/terminal/session/{sessionId}', [TerminalController::class, 'destroySession']);

        // Monitor routes
        Route::post('/api/monitor/session', [MonitorController::class, 'createSession']);
        Route::delete('/api/monitor/session/{sessionId}', [MonitorController::class, 'destroySession']);

        // Update routes
        Route::get('/api/updates/status', [UpdateController::class, 'status']);
        Route::post('/api/updates/check', [UpdateController::class, 'check']);
        Route::get('/api/updates/check/{jobId}', [UpdateController::class, 'checkStatus']);
        Route::post('/api/updates/upgrade', [UpdateController::class, 'upgrade']);
        Route::get('/api/updates/upgrade/{jobId}', [UpdateController::class, 'upgradeStatus']);
        Route::get('/api/updates/available', [UpdateController::class, 'availableUpdates']);
        Route::post('/api/updates/clean-cache', [UpdateController::class, 'cleanCache']);
        Route::get('/api/updates/reboot-status', [UpdateController::class, 'rebootStatus']);
        Route::post('/api/updates/clear-badge', [UpdateController::class, 'clearBadge']);
        Route::post('/api/updates/restart', [SystemController::class, 'restart']);
        Route::post('/api/updates/shutdown', [SystemController::class, 'shutdown']);

        // NovaNAS update routes
        Route::get('/api/updates/novanas/status', [UpdateController::class, 'novaNasStatus']);
        Route::post('/api/updates/novanas/update', [UpdateController::class, 'novaNasUpdate']);

        // Application store routes
        Route::get('/api/applications/stores', [ApplicationsController::class, 'stores']);
        Route::get('/api/applications/installed', [ApplicationsController::class, 'installed']);
        Route::get('/api/applications/{store}/categories', [ApplicationsController::class, 'categories']);
        Route::get('/api/applications/{store}/apps', [ApplicationsController::class, 'apps']);
        Route::get('/api/applications/{store}/apps/{app}', [ApplicationsController::class, 'show']);
        Route::post('/api/applications/{store}/apps/{app}/install', [ApplicationsController::class, 'install']);
        Route::post('/api/applications/{store}/apps/{app}/update', [ApplicationsController::class, 'update']);
        Route::post('/api/applications/{store}/apps/{app}/stop', [ApplicationsController::class, 'stop']);
        Route::post('/api/applications/{store}/apps/{app}/start', [ApplicationsController::class, 'start']);
        Route::delete('/api/applications/{store}/apps/{app}', [ApplicationsController::class, 'destroy']);
        Route::get('/api/applications/{store}/apps/{app}/status', [ApplicationsController::class, 'status']);

        // Backup routes
        Route::get('/api/backup/repositories', [BackupRepositoryController::class, 'index']);
        Route::post('/api/backup/repositories', [BackupRepositoryController::class, 'store']);
        Route::get('/api/backup/repositories/{repository}', [BackupRepositoryController::class, 'show']);
        Route::put('/api/backup/repositories/{repository}', [BackupRepositoryController::class, 'update']);
        Route::delete('/api/backup/repositories/{repository}', [BackupRepositoryController::class, 'destroy']);
        Route::post('/api/backup/repositories/{repository}/check', [BackupRepositoryController::class, 'check']);
        Route::get('/api/backup/repositories/{repository}/stats', [BackupRepositoryController::class, 'stats']);
        Route::get('/api/backup/provider-fields', [BackupRepositoryController::class, 'providerFields']);
        Route::post('/api/backup/test-connection', [BackupRepositoryController::class, 'testConnection']);

        Route::get('/api/backup/jobs', [BackupJobController::class, 'index']);
        Route::post('/api/backup/jobs', [BackupJobController::class, 'store']);
        Route::get('/api/backup/jobs/{job}', [BackupJobController::class, 'show']);
        Route::put('/api/backup/jobs/{job}', [BackupJobController::class, 'update']);
        Route::delete('/api/backup/jobs/{job}', [BackupJobController::class, 'destroy']);
        Route::post('/api/backup/jobs/{job}/run', [BackupJobController::class, 'run']);
        Route::post('/api/backup/jobs/{job}/enable', [BackupJobController::class, 'enable']);
        Route::post('/api/backup/jobs/{job}/disable', [BackupJobController::class, 'disable']);
        Route::get('/api/backup/jobs/{job}/executions', [BackupJobController::class, 'executions']);
        Route::get('/api/backup/jobs/{job}/executions/{execution}/logs', [BackupJobController::class, 'logs']);

        Route::get('/api/backup/repositories/{repository}/snapshots', [BackupSnapshotController::class, 'index']);
        Route::delete('/api/backup/repositories/{repository}/snapshots/{snapshotId}', [BackupSnapshotController::class, 'destroy']);

        // Backup server routes
        Route::get('/api/backup/server/keys', [BackupServerController::class, 'listKeys']);
        Route::post('/api/backup/server/keys', [BackupServerController::class, 'storeKey']);
        Route::delete('/api/backup/server/keys/{name}', [BackupServerController::class, 'destroyKey']);
        Route::get('/api/backup/server/status', [BackupServerController::class, 'status']);
        Route::put('/api/backup/server/path', [BackupServerController::class, 'updatePath']);

        Route::get('/api/backup/server/machine-id', [BackupServerController::class, 'machineId']);

        // Support routes
        Route::get('/api/support/system-info', [SupportController::class, 'systemInfo']);
        Route::post('/api/support/tickets', [SupportController::class, 'store']);
        Route::get('/api/support/tickets/{ticketId}/messages', [SupportController::class, 'messages']);
        Route::post('/api/support/tickets/{ticketId}/messages', [SupportController::class, 'sendMessage']);
        Route::put('/api/support/tickets/{ticketId}/messages/{messageId}', [SupportController::class, 'editMessage']);
    });

    // API routes - exclude Inertia middleware
});

// Public endpoint to identify a NovaNAS instance (no middleware)
Route::get('/api/backup/server/identify', [BackupServerController::class, 'identify']);
Route::passkeys();
