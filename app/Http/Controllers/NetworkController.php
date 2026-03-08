<?php

namespace App\Http\Controllers;

use App\Services\NetworkService;
use Illuminate\Http\JsonResponse;

class NetworkController extends Controller
{
    public function __construct(
        protected NetworkService $networkService
    ) {}

    /**
     * Get all network interfaces.
     */
    public function index(): JsonResponse
    {
        $interfaces = $this->networkService->getNetworkInterfaces();

        return response()->json($interfaces);
    }

    /**
     * Get configuration for a specific interface.
     */
    public function getConfig(string $interface): JsonResponse
    {
        $config = $this->networkService->parseInterfaceConfig($interface);

        return response()->json($config);
    }

    /**
     * Set network configuration for a specific interface.
     */
    public function setConfig(): JsonResponse
    {
        $interface = request()->input('interface');
        $method = request()->input('method', 'dhcp');
        $ip = request()->input('ip');
        $netmask = request()->input('netmask', '255.255.255.0');
        $gateway = request()->input('gateway');

        if (empty($interface)) {
            return response()->json(['error' => 'Interface name is required'], 422);
        }

        if ($method === 'static' && (empty($ip) || empty($netmask))) {
            return response()->json(['error' => 'IP address and netmask are required for static configuration'], 422);
        }

        try {
            $this->networkService->applyNetworkConfig($interface, $method, $ip, $netmask, $gateway);

            return response()->json(['success' => true, 'message' => "Network configuration applied for {$interface}"]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
