<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Services\Storage\SmartService;

class SmartController extends Controller
{
    public function __construct(
        protected SmartService $smartService
    ) {}

    /**
     * Get SMART health status for all disks.
     */
    public function health(): JsonResponse
    {
        $healthData = $this->smartService->getAllDisksHealth();

        return response()->json([
            'disks' => $healthData,
        ]);
    }

    /**
     * Get SMART health status for a specific disk.
     */
    public function healthStatus(string $device): JsonResponse
    {
        $health = $this->smartService->getHealthStatus($device);

        if ($health === null) {
            return response()->json([
                'error' => 'Unable to get SMART status for device',
            ], 404);
        }

        return response()->json($health);
    }

    /**
     * Get SMART test results for a specific disk.
     */
    public function testResults(string $device): JsonResponse
    {
        $results = $this->smartService->getTestResults($device);

        if ($results === null) {
            return response()->json([
                'error' => 'Unable to get SMART test results for device',
            ], 404);
        }

        return response()->json([
            'device' => $device,
            'tests' => $results,
        ]);
    }

    /**
     * Start a SMART test on a specific disk.
     */
    public function startTest(string $device, string $type = 'short'): JsonResponse
    {
        $success = $type === 'long'
            ? $this->smartService->startLongTest($device)
            : $this->smartService->startShortTest($device);

        if (!$success) {
            return response()->json([
                'error' => 'Failed to start SMART test on device',
            ], 500);
        }

        return response()->json([
            'message' => 'SMART test started successfully',
            'type' => $type,
        ]);
    }

    /**
     * Start SMART tests on all disks.
     */
    public function scanAll(string $type = 'short'): JsonResponse
    {
        $results = $this->smartService->runTestsOnAllDisks($type);

        $successCount = count(array_filter($results, fn($r) => $r === true));
        $totalCount = count($results);

        return response()->json([
            'message' => "Started SMART {$type} test on {$successCount} of {$totalCount} disks",
            'type' => $type,
            'results' => $results,
        ]);
    }

    /**
     * Get detailed SMART information for a specific disk.
     */
    public function detailedInfo(string $device): JsonResponse
    {
        $info = $this->smartService->getDetailedInfo($device);

        if ($info === null) {
            return response()->json([
                'error' => 'Unable to get detailed SMART info for device',
            ], 404);
        }

        return response()->json([
            'device' => $device,
            'info' => $info,
        ]);
    }
}
