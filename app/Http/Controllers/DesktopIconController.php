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
     * Update icon position for the authenticated user.
     */
    public function updatePosition(DesktopApp $desktopApp, UpdateIconPositionRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Find or create the user desktop icon entry
        $userIcon = UserDesktopIcon::updateOrCreate(
            [
                'user_id' => $user->id,
                'desktop_app_id' => $desktopApp->id,
            ],
            [
                'position_x' => $validated['position_x'],
                'position_y' => $validated['position_y'],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $userIcon,
        ]);
    }

    /**
     * Update multiple icon positions at once.
     */
    public function updatePositions(Request $request): JsonResponse
    {
        $request->validate([
            'positions' => ['required', 'array'],
            'positions.*.desktop_app_id' => ['required', 'integer', 'exists:desktop_apps,id'],
            'positions.*.position_x' => ['required', 'integer', 'min:0'],
            'positions.*.position_y' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $positions = $request->input('positions');

        foreach ($positions as $position) {
            UserDesktopIcon::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'desktop_app_id' => $position['desktop_app_id'],
                ],
                [
                    'position_x' => $position['position_x'],
                    'position_y' => $position['position_y'],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Positions updated successfully',
        ]);
    }

    /**
     * Toggle icon visibility on desktop.
     */
    public function toggleVisibility(Request $request): JsonResponse
    {
        $request->validate([
            'desktop_app_id' => ['required', 'integer', 'exists:desktop_apps,id'],
            'is_visible_desktop' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $userIcon = UserDesktopIcon::updateOrCreate(
            [
                'user_id' => $user->id,
                'desktop_app_id' => $request->input('desktop_app_id'),
            ],
            [
                'is_visible_desktop' => $request->input('is_visible_desktop'),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $userIcon,
        ]);
    }

    /**
     * Get all icon positions for the authenticated user.
     */
    public function positions(): JsonResponse
    {
        $user = auth()->user();

        $icons = UserDesktopIcon::where('user_id', $user->id)->get();

        return response()->json([
            'success' => true,
            'data' => $icons,
        ]);
    }
}
