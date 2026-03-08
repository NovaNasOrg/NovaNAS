<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\WizardController;
use App\Http\Controllers\DesktopIconController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\DynDnsController;
use App\Http\Controllers\UpnpController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\StorageController;

// Wizard routes (accessible without authentication when no users exist)
Route::get('/wizard', [WizardController::class, 'index']);
Route::get('/wizard/account', [WizardController::class, 'account']);
Route::post('/wizard/account', [WizardController::class, 'storeAccount']);
Route::get('/wizard/skip', [WizardController::class, 'skip']);

Route::get('/login', [AuthController::class, 'login'])->name('login');

Route::post('/login', [AuthController::class, 'authenticate']);

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');


Route::group(['middleware' => 'auth'], function () {
    Route::get('/', [HomeController::class, 'index']);

    Route::withoutMiddleware(\App\Http\Middleware\HandleInertiaRequests::class)->group(function () {
        Route::get('/api/system/info', [SystemController::class, 'info']);
        Route::get('/api/system/network-interfaces', [SystemController::class, 'networkInterfaces']);
        Route::get('/api/system/network-config', [SystemController::class, 'getNetworkConfig']);
        Route::get('/api/system/interface-config/{interface}', [SystemController::class, 'getInterfaceConfig']);
        Route::post('/api/system/network-config', [SystemController::class, 'setNetworkConfig']);

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

        // UPNP routes
        Route::get('/api/upnp/rules', [UpnpController::class, 'index']);
        Route::post('/api/upnp/rules', [UpnpController::class, 'store']);
        Route::put('/api/upnp/rules/{id}', [UpnpController::class, 'update']);
        Route::delete('/api/upnp/rules/{id}', [UpnpController::class, 'destroy']);
        Route::post('/api/upnp/publish-all', [UpnpController::class, 'publishAll']);
        Route::get('/api/upnp/discover', [UpnpController::class, 'discover']);
        Route::get('/api/upnp/interfaces', [UpnpController::class, 'getInterfaces']);

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

        // Storage routes
        Route::get('/api/storage/disks', [StorageController::class, 'disks']);
        Route::get('/api/storage/disks/{device}/capacity', [StorageController::class, 'capacity']);
        Route::get('/api/storage/pools', [StorageController::class, 'pools']);
        Route::get('/api/storage/pools/{pool}', [StorageController::class, 'pool']);
    });

    // API routes - exclude Inertia middleware
});
