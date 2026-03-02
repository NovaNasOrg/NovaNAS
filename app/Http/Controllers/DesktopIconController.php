<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIconPositionRequest;
use App\Models\DesktopApp;
use App\Models\UserDesktopIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopIconController extends Controller
{
    /**
     * Update icon order for the authenticated user.
     * This handles reordering icons via drag and drop.
     */
    public function updateOrder(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => ['required', 'array'],
            'orders.*.desktop_app_id' => ['required', 'integer', 'exists:desktop_apps,id'],
            'orders.*.order' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $orders = $request->input('orders');

        foreach ($orders as $item) {
            UserDesktopIcon::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'desktop_app_id' => $item['desktop_app_id'],
                ],
                [
                    'order' => $item['order'],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Icon order updated successfully',
        ]);
    }

    /**
     * Toggle icon visibility on desktop.
     */
    public function toggleVisibility(Request $request): JsonResponse
    {
        $request->validate([
            'desktop_app_id' => ['required', 'integer', 'exists:desktop_apps,id'],
            'is_visible' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $userIcon = UserDesktopIcon::updateOrCreate(
            [
                'user_id' => $user->id,
                'desktop_app_id' => $request->input('desktop_app_id'),
            ],
            [
                'is_visible' => $request->input('is_visible'),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $userIcon,
        ]);
    }

    /**
     * Get all icon orders for the authenticated user.
     */
    public function orders(): JsonResponse
    {
        $user = auth()->user();

        $icons = UserDesktopIcon::where('user_id', $user->id)->get();

        return response()->json([
            'success' => true,
            'data' => $icons,
        ]);
    }
}
