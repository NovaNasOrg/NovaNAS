<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpnpRuleRequest;
use App\Models\UpnpRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Controller for managing UPNP port mappings.
 */
class UpnpController extends Controller
{
    /**
     * Get all UPNP rules.
     */
    public function index(): JsonResponse
    {
        $rules = UpnpRule::orderBy('created_at', 'desc')->get();

        return response()->json([
            'rules' => $rules->map(fn ($rule) => $this->formatRule($rule)),
        ]);
    }

    /**
     * Store a new UPNP rule.
     */
    public function store(StoreUpnpRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rule = UpnpRule::create([
            'name' => $validated['name'],
            'interface' => $validated['interface'],
            'external_port' => $validated['external_port'],
            'internal_port' => $validated['internal_port'],
            'protocol' => $validated['protocol'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'remote_host' => $validated['remote_host'] ?? '',
        ]);

        // If enabled, publish the rule immediately
        if ($rule->is_enabled) {
            $publishResult = $rule->publish();

            if (!$publishResult['success']) {
                return response()->json([
                    'message' => 'Rule created but failed to publish: ' . $publishResult['message'],
                    'rule' => $this->formatRule($rule),
                ], 201);
            }
        }

        return response()->json([
            'message' => 'UPNP rule created successfully.',
            'rule' => $this->formatRule($rule),
        ], 201);
    }

    /**
     * Update an existing UPNP rule.
     */
    public function update(StoreUpnpRuleRequest $request, int $id): JsonResponse
    {
        $rule = UpnpRule::findOrFail($id);
        $validated = $request->validated();

        // Store old values for comparison
        $oldExternalPort = $rule->external_port;
        $oldProtocol = $rule->protocol;

        // Update the rule
        $rule->update([
            'name' => $validated['name'],
            'interface' => $validated['interface'],
            'external_port' => $validated['external_port'],
            'internal_port' => $validated['internal_port'],
            'protocol' => $validated['protocol'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'remote_host' => $validated['remote_host'] ?? '',
        ]);

        // Handle UPNP mapping changes
        if ($rule->is_enabled) {
            $this->deleteUpnpMapping($oldExternalPort, $oldProtocol);

            $publishResult = $rule->publish();

            if (!$publishResult['success']) {
                return response()->json([
                    'message' => 'Rule updated but failed to publish: ' . $publishResult['message'],
                    'rule' => $this->formatRule($rule->fresh()),
                ]);
            }
        } else {
            // If disabled, remove the mapping
            $this->deleteUpnpMapping($rule->external_port, $rule->protocol);
        }

        return response()->json([
            'message' => 'UPNP rule updated successfully.',
            'rule' => $this->formatRule($rule->fresh()),
        ]);
    }

    /**
     * Delete a UPNP rule.
     */
    public function destroy(int $id): JsonResponse
    {
        $rule = UpnpRule::findOrFail($id);

        // First remove the mapping from router
        $this->deleteUpnpMapping($rule->external_port, $rule->protocol);

        // Then delete from database
        $rule->delete();

        return response()->json([
            'message' => 'UPNP rule deleted successfully.',
        ]);
    }

    /**
     * Publish all enabled UPNP rules.
     */
    public function publishAll(): JsonResponse
    {
        $rules = UpnpRule::enabled()->get();

        if ($rules->isEmpty()) {
            return response()->json([
                'message' => 'No enabled UPNP rules found.',
                'results' => [],
            ]);
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($rules as $rule) {
            $result = $rule->publish();
            $results[] = [
                'id' => $rule->id,
                'name' => $rule->name,
                'success' => $result['success'],
                'message' => $result['message'],
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }

            $rule->refresh();
        }

        return response()->json([
            'message' => "Publish complete: {$successCount} success, {$failureCount} failure(s).",
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ]);
    }

    /**
     * Discover UPNP devices on the network.
     */
    public function discover(): JsonResponse
    {
        // Use miniupnpc to discover UPNP devices
        // upnpc -l lists current mappings
        $command = 'sudo upnpc -l 2>&1';

        $output = shell_exec($command);

        // Check for successful discovery
        if (str_contains($output, 'ExternalIPAddress = ')) {
            // Extract external IP
            preg_match('/ExternalIPAddress = (\S+)/', $output, $externalIpMatch);
            $externalIp = $externalIpMatch[1] ?? '';

            // Extract router's LAN IP from the UPNP device description URL
            // Format: desc: http://192.168.0.1:1900/nmrpq/rootDesc.xml
            preg_match('/desc: http:\/\/(\S+):/', $output, $routerIpMatch);
            $lanIp = $routerIpMatch[1] ?? '';

            // Fallback to Local LAN ip address if router IP not found
            if (empty($lanIp)) {
                preg_match('/Local LAN ip address : (\S+)/', $output, $lanIpMatch);
                $lanIp = $lanIpMatch[1] ?? '';
            }

            return response()->json([
                'found' => true,
                'device_count' => 1,
                'lan_address' => $lanIp,
                'external_ip' => $externalIp,
                'message' => 'Found UPNP device',
            ]);
        }

        // Check for common error messages and simplify them
        if (str_contains($output, 'No valid UPNP Internet Gateway Device found')) {
            return response()->json([
                'found' => false,
                'device_count' => 0,
                'lan_address' => '',
                'external_ip' => '',
                'message' => 'No UPNP devices found on the network.',
            ]);
        }

        if (str_contains($output, 'No UPnP device found')) {
            return response()->json([
                'found' => false,
                'device_count' => 0,
                'lan_address' => '',
                'external_ip' => '',
                'message' => 'No UPNP devices found on the network.',
            ]);
        }

        if (str_contains($output, 'failed') || str_contains($output, 'error')) {
            return response()->json([
                'found' => false,
                'device_count' => 0,
                'lan_address' => '',
                'external_ip' => '',
                'message' => 'Failed to discover UPNP devices.',
            ], 500);
        }

        return response()->json([
            'found' => false,
            'device_count' => 0,
            'lan_address' => '',
            'external_ip' => '',
            'message' => 'Failed to discover UPNP devices.',
        ], 500);
    }

    /**
     * Get available network interfaces (reuses NetworkController logic).
     */
    public function getInterfaces(): JsonResponse
    {
        $configuredInterfaces = $this->getConfiguredInterfaces();
        $interfaces = [];

        $sysClassNet = '/sys/class/net';
        if (!File::exists($sysClassNet)) {
            return response()->json($interfaces);
        }

        $devices = File::directories($sysClassNet);
        if (!$devices) {
            return response()->json($interfaces);
        }

        foreach ($devices as $devicePath) {
            $name = basename($devicePath);

            if ($name === 'lo') {
                continue;
            }

            $interface = $this->getInterfaceInfo($name);

            if (in_array($name, $configuredInterfaces, true)) {
                $interfaces[] = $interface;
            }
        }

        return response()->json($interfaces);
    }

    /**
     * Format a rule for JSON response.
     *
     * @return array<string, mixed>
     */
    protected function formatRule(UpnpRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'interface' => $rule->interface,
            'internal_ip' => $rule->getInternalIp(),
            'external_port' => $rule->external_port,
            'internal_port' => $rule->internal_port,
            'protocol' => $rule->protocol,
            'description' => $rule->description,
            'is_enabled' => $rule->is_enabled,
            'remote_host' => $rule->remote_host,
            'last_renewed_at' => $rule->last_renewed_at?->toIso8601String(),
            'created_at' => $rule->created_at->toIso8601String(),
            'updated_at' => $rule->updated_at->toIso8601String(),
        ];
    }

    /**
     * Delete UPNP mapping from router.
     */
    protected function deleteUpnpMapping(int $port, string $protocol): void
    {
        // Use miniupnpc with sudo to remove port mapping
        // upnpc -d <external_port> <protocol>
        $command = sprintf(
            'sudo upnpc -d %d %s 2>&1',
            $port,
            $protocol
        );

        shell_exec($command);
    }

    /**
     * Get configured interfaces from /etc/network/interfaces.
     *
     * @return array<int, string>
     */
    protected function getConfiguredInterfaces(): array
    {
        $interfaces = [];

        $interfacesD = '/etc/network/interfaces.d';
        if (File::exists($interfacesD)) {
            $files = File::files($interfacesD);
            foreach ($files as $file) {
                $content = File::get($file->getPathname());
                $interfaces = array_merge($interfaces, $this->extractInterfacesFromContent($content));
            }
        }

        if (File::exists('/etc/network/interfaces')) {
            $content = File::get('/etc/network/interfaces');
            $interfaces = array_merge($interfaces, $this->extractInterfacesFromContent($content));
        }

        return array_unique($interfaces);
    }

    /**
     * Extract interface names from content.
     *
     * @return array<int, string>
     */
    protected function extractInterfacesFromContent(string $content): array
    {
        $interfaces = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^iface\s+(\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }

            if (preg_match('/^auto\s+(\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }
        }

        return $interfaces;
    }

    /**
     * Get interface info.
     *
     * @return array{name: string, ipv4: string|null}
     */
    protected function getInterfaceInfo(string $name): array
    {
        $interface = [
            'name' => $name,
            'ipv4' => null,
        ];

        $result = Process::run("ip -4 addr show {$name} 2>/dev/null");
        if ($result->successful()) {
            $output = $result->output();
            if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $output, $matches)) {
                $interface['ipv4'] = $matches[1];
            }
        }

        return $interface;
    }
}
