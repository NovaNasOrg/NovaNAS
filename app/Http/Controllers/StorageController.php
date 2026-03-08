<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Services\Storage\StorageService;

class StorageController extends Controller
{
    public function __construct(
        protected StorageService $storageService
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
}
