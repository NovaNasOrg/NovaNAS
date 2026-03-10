<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Storage\StorageService;
use App\Services\SettingsService;

class StorageController extends Controller
{
    public function __construct(
        protected StorageService $storageService,
        protected SettingsService $settingsService
    ) {}

    /**
     * List all disks in the system.
     */
    public function disks(): JsonResponse
    {
        $disks = $this->storageService->listDisks();

        return response()->json([
            'disks' => $disks,
        ]);
    }

    /**
     * Get capacity information for a specific disk.
     */
    public function capacity(string $device): JsonResponse
    {
        $capacity = $this->storageService->getCapacity($device);

        if ($capacity === null) {
            return response()->json([
                'error' => 'Unable to get capacity for device',
            ], 404);
        }

        return response()->json($capacity);
    }

    /**
     * List all storage pools (ZFS).
     */
    public function pools(): JsonResponse
    {
        $pools = $this->storageService->listPools();

        return response()->json([
            'pools' => $pools,
        ]);
    }

    /**
     * Get detailed information about a specific pool.
     */
    public function pool(string $pool): JsonResponse
    {
        $info = $this->storageService->getPoolInfo($pool);

        if ($info === null) {
            return response()->json([
                'error' => 'Pool not found',
            ], 404);
        }

        return response()->json($info);
    }

    /**
     * Get settings by keys.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $keys = $request->input('keys', []);

        if (empty($keys)) {
            $keys = ['storage.user_files_home', 'storage.app_folders_home'];
        }

        $settings = $this->settingsService->getMultiple($keys);

        return response()->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Update settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        return response()->json([
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * List directories in a storage pool's mountpoint.
     */
    public function poolDirectories(string $pool): JsonResponse
    {
        $directories = $this->settingsService->listDirectoriesInPool($pool);

        return response()->json([
            'directories' => $directories,
        ]);
    }
}
