<?php

namespace App\Http\Controllers;

use App\Models\DesktopApp;
use App\Models\UserDesktopIcon;
use Inertia\Inertia;

class HomeController extends Controller
{
    /**
     * Display the home page.
     */
    public function index()
    {
        $user = auth()->user();

        // Get desktop apps visible for the current user
        $desktopApps = DesktopApp::query()
            ->visibleFor($user)
            ->orderBy('name')
            ->get();

        // Get user icon orders
        $userIconOrders = UserDesktopIcon::where('user_id', $user->id)
            ->where('is_visible', true)
            ->get()
            ->keyBy('desktop_app_id');

        return Inertia::render('Home', [
            'version' => config('app.version'),
            'desktopApps' => $desktopApps,
            'userIconOrders' => $userIconOrders,
        ]);
    }
}
