<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\WizardController;
use App\Http\Controllers\DesktopIconController;

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
    Route::get('/api/system/info', [SystemController::class, 'info']);

    // Desktop icon routes - order based (simple 1, 2, 3, 4...)
    Route::put('/api/desktop-icons/order', [DesktopIconController::class, 'updateOrder']);
    Route::put('/api/desktop-icons/visibility', [DesktopIconController::class, 'toggleVisibility']);
    Route::get('/api/desktop-icons/orders', [DesktopIconController::class, 'orders']);
});
