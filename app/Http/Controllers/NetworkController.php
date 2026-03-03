<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class NetworkController extends Controller
{
    /**
     * Get all network interfaces.
     */
    public function index(): JsonResponse
    {
        $interfaces = $this->getNetworkInterfaces();

        return response()->json($interfaces);
    }

    /**
     * Get configuration for a specific interface.
     */
    public function getConfig(string $interface): JsonResponse
    {
        $config = $this->parseInterfaceConfig($interface);

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
            $this->applyNetworkConfig($interface, $method, $ip, $netmask, $gateway);

            return response()->json(['success' => true, 'message' => "Network configuration applied for {$interface}"]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all network interfaces with their status.
     *
     * @return array<int, array>
     */
    protected function getNetworkInterfaces(): array
    {
        // Get list of configured interfaces from /etc/network/interfaces
        $configuredInterfaces = $this->getConfiguredInterfaces();

        $interfaces = [];

        // Get list of network interfaces from /sys/class/net
        $sysClassNet = '/sys/class/net';
        if (!File::exists($sysClassNet)) {
            return $interfaces;
        }

        $devices = File::directories($sysClassNet);
        if (!$devices) {
            return $interfaces;
        }

        foreach ($devices as $devicePath) {
            $name = basename($devicePath);

            // Skip loopback
            if ($name === 'lo') {
                continue;
            }

            // Get interface info
            $interface = $this->getInterfaceInfo($name);

            // Only include interfaces that are configured in /etc/network/interfaces
            if (in_array($name, $configuredInterfaces, true)) {
                $interfaces[] = $interface;
            }
        }

        return $interfaces;
    }

    /**
     * Get list of interface names from /etc/network/interfaces.
     *
     * @return array<int, string>
     */
    protected function getConfiguredInterfaces(): array
    {
        $interfaces = [];

        // Check interfaces.d directory first
        $interfacesD = '/etc/network/interfaces.d';
        if (File::exists($interfacesD)) {
            $files = File::files($interfacesD);
            foreach ($files as $file) {
                $content = File::get($file->getPathname());
                $interfaces = array_merge($interfaces, $this->extractInterfacesFromContent($content));
            }
        }

        // Also check main interfaces file
        if (File::exists('/etc/network/interfaces')) {
            $content = File::get('/etc/network/interfaces');
            $interfaces = array_merge($interfaces, $this->extractInterfacesFromContent($content));
        }

        return array_unique($interfaces);
    }

    /**
     * Extract interface names from interfaces file content.
     *
     * @return array<int, string>
     */
    protected function extractInterfacesFromContent(string $content): array
    {
        $interfaces = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Match iface lines (e.g., "iface eth0 inet dhcp")
            if (preg_match('/^iface\s+(\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }

            // Match auto lines (e.g., "auto eth0")
            if (preg_match('/^auto\s+(\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }

            // Match mapping lines (e.g., "mapping eth0")
            if (preg_match('/^mapping\s+(\S+)/', $line, $matches)) {
                $interfaces[] = $matches[1];
            }
        }

        return $interfaces;
    }

    /**
     * Get information about a specific interface.
     *
     * @return array{name: string, type: string, ipv4: string|null, mac: string|null, state: string}
     */
    protected function getInterfaceInfo(string $name): array
    {
        $interface = [
            'name' => $name,
            'type' => 'physical',
            'ipv4' => null,
            'mac' => null,
            'state' => 'unknown',
        ];

        // Get MAC address
        $macPath = "/sys/class/net/{$name}/address";
        if (File::exists($macPath)) {
            $interface['mac'] = trim(File::get($macPath));
            // Check if it's a virtual interface
            if (str_starts_with($interface['mac'], '00:00:00:00:00:00') ||
                str_starts_with($interface['mac'], 'ff:ff:ff:ff:ff')) {
                $interface['type'] = 'virtual';
            }
        }

        // Get operstate
        $statePath = "/sys/class/net/{$name}/operstate";
        if (File::exists($statePath)) {
            $interface['state'] = trim(File::get($statePath));
        }

        // Get IPv4 address using ip command
        $result = Process::run("ip -4 addr show {$name} 2>/dev/null");
        if ($result->successful()) {
            $output = $result->output();
            if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $output, $matches)) {
                $interface['ipv4'] = $matches[1];
            }
        }

        return $interface;
    }

    /**
     * Parse interface configuration from ifupdown files.
     *
     * @return array{method: string, ip: string|null, netmask: string|null, gateway: string|null}
     */
    protected function parseInterfaceConfig(string $interface): array
    {
        $config = [
            'method' => 'dhcp',
            'ip' => null,
            'netmask' => null,
            'gateway' => null,
        ];

        // Check interfaces.d directory first
        $interfacesD = '/etc/network/interfaces.d';
        $configFile = null;

        if (File::exists($interfacesD)) {
            $files = File::files($interfacesD);
            foreach ($files as $file) {
                $content = File::get($file->getPathname());
                if (str_contains($content, "iface {$interface}")) {
                    $configFile = $file->getPathname();
                    break;
                }
            }
        }

        // Fall back to main interfaces file
        if (!$configFile && File::exists('/etc/network/interfaces')) {
            $content = File::get('/etc/network/interfaces');
            if (str_contains($content, "iface {$interface}")) {
                $configFile = '/etc/network/interfaces';
            }
        }

        if ($configFile) {
            $content = File::get($configFile);
            $lines = explode("\n", $content);

            $inInterfaceBlock = false;
            foreach ($lines as $line) {
                $line = trim($line);

                // Skip comments and empty lines
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }

                // Check if we're entering the interface block
                if (preg_match("/iface\s+{$interface}\s+inet\s+(\w+)/", $line, $matches)) {
                    $inInterfaceBlock = true;
                    $config['method'] = $matches[1];
                    continue;
                }

                // If we're in the interface block, parse the settings
                if ($inInterfaceBlock) {
                    // Check for address (IP)
                    if (preg_match('/address\s+(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                        $config['ip'] = $matches[1];
                    }
                    // Check for netmask
                    elseif (preg_match('/netmask\s+(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                        $config['netmask'] = $matches[1];
                    }
                    // Check for gateway
                    elseif (preg_match('/gateway\s+(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
                        $config['gateway'] = $matches[1];
                    }
                    // Check if we're leaving the interface block
                    elseif (str_starts_with($line, 'iface ') || str_starts_with($line, 'auto ')) {
                        break;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * Apply network configuration using ifupdown.
     */
    protected function applyNetworkConfig(string $interface, string $method, ?string $ip, ?string $netmask, ?string $gateway): void
    {
        // Generate ifupdown configuration
        $config = $this->generateIfupdownConfig($interface, $method, $ip, $netmask, $gateway);

        // Check for and handle existing configuration files
        $this->handleExistingConfigFiles($interface);

        // Write configuration to interfaces.d
        $configDir = '/etc/network/interfaces.d';
        if (!File::exists($configDir)) {
            File::makeDirectory($configDir, 0755, true);
        }

        $configFile = "{$configDir}/{$interface}.conf";
        File::put($configFile, $config);

        // Bring interface down, then up to apply changes
        Process::run("ip link set {$interface} down");
        usleep(500000); // Wait 500ms
        Process::run("ip link set {$interface} up");
        usleep(500000); // Wait 500ms

        // For DHCP, we may need to request a new IP
        if ($method === 'dhcp') {
            Process::run("dhclient -r {$interface} 2>/dev/null");
            usleep(200000); // Wait 200ms
            Process::run("dhclient {$interface} 2>/dev/null");
        }
    }

    /**
     * Handle existing configuration files for an interface.
     * Backs up existing configs and removes stale config files.
     */
    protected function handleExistingConfigFiles(string $interface): void
    {
        $configDir = '/etc/network/interfaces.d';
        $backupDir = '/var/backups/novanas/network';

        // Check for existing config file for this interface
        $configFile = "{$configDir}/{$interface}.conf";
        if (File::exists($configFile)) {
            // Create backup directory if it doesn't exist
            if (!File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            // Backup existing configuration
            $timestamp = date('Y-m-d_His');
            $backupFile = "{$backupDir}/{$interface}_{$timestamp}.conf.bak";
            File::copy($configFile, $backupFile);
        }

        // Check for any other config files that might reference this interface
        if (File::exists($configDir)) {
            $files = File::files($configDir);
            foreach ($files as $file) {
                $filename = $file->getFilename();

                // Skip our own config file
                if ($filename === "{$interface}.conf") {
                    continue;
                }

                // Check if this file contains configuration for our interface
                $content = File::get($file->getPathname());
                if (str_contains($content, "iface {$interface}") ||
                    str_contains($content, "auto {$interface}") ||
                    str_contains($content, "mapping {$interface}")) {

                    // Create backup directory if it doesn't exist
                    if (!File::exists($backupDir)) {
                        File::makeDirectory($backupDir, 0755, true);
                    }

                    // Backup and remove stale config
                    $timestamp = date('Y-m-d_His');
                    $backupFile = "{$backupDir}/{$filename}_{$timestamp}.bak";
                    File::move($file->getPathname(), $backupFile);
                }
            }
        }

        // Also check main interfaces file for references to this interface
        $mainInterfaces = '/etc/network/interfaces';
        if (File::exists($mainInterfaces)) {
            $content = File::get($mainInterfaces);
            if (str_contains($content, "iface {$interface}") ||
                str_contains($content, "auto {$interface}")) {

                // Create backup directory if it doesn't exist
                if (!File::exists($backupDir)) {
                    File::makeDirectory($backupDir, 0755, true);
                }

                // Backup main interfaces file
                $timestamp = date('Y-m-d_His');
                $backupFile = "{$backupDir}/interfaces_{$timestamp}.bak";
                File::copy($mainInterfaces, $backupFile);
            }
        }
    }

    /**
     * Generate ifupdown configuration content.
     */
    protected function generateIfupdownConfig(string $interface, string $method, ?string $ip, ?string $netmask, ?string $gateway): string
    {
        $config = "# NovaNAS Network Configuration for {$interface}\n";
        $config .= "# Auto-generated - Do not edit manually\n\n";
        $config .= "auto {$interface}\n";
        $config .= "iface {$interface} inet ";

        if ($method === 'dhcp') {
            $config .= "dhcp\n";
        } else {
            $config .= "static\n";
            $config .= "    address {$ip}\n";
            $config .= "    netmask {$netmask}\n";
            if ($gateway) {
                $config .= "    gateway {$gateway}\n";
            }
        }

        return $config;
    }
}
