<?php

namespace App\Http\Controllers;

use App\Models\DesktopDevice;
use App\Services\DesktopAccessService;
use App\Services\NetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopAccessController extends Controller
{
    public function __construct(
        private DesktopAccessService $desktopAccessService,
        private NetworkService $networkService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'shares' => $this->desktopAccessService->foldersForUser($request->user()),
            'ip_address' => $this->networkService->getDefaultIPAddress(),
            'user_id' => $request->user()->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:darwin,win32,linux'],
        ]);

        try {
            $provisioned = $this->desktopAccessService->provisionDevice(
                $request->user(),
                $validated['uuid'],
                $validated['name'],
                $validated['platform'],
            );

            return response()->json([
                'device_id' => $provisioned['device']->uuid,
                'username' => $provisioned['device']->samba_username,
                'password' => $provisioned['password'],
                'shares' => $this->desktopAccessService->foldersForUser($request->user()),
                'ip_address' => $this->networkService->getDefaultIPAddress(),
                'user_id' => $request->user()->id,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $device = DesktopDevice::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $this->desktopAccessService->revokeDevice($request->user(), $device);

            return response()->json(['message' => 'This computer no longer has folder access.']);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
