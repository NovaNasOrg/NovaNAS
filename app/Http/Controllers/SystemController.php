<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class SystemController extends Controller
{
    /**
     * Get system information including date/time from the OS.
     */
    public function info(): JsonResponse
    {
        // Get system date/time from the OS
        $dateTime = now()->format('Y-m-d H:i:s');

        // Get additional system info
        $uptime = $this->getUptime();
        $loadAverage = $this->getLoadAverage();
        $cpuUsage = $this->getCpuUsage();
        $memoryUsage = $this->getMemoryUsage();

        return response()->json([
            'datetime' => $dateTime,
            'timestamp' => now()->timestamp,
            'timezone' => config('app.timezone'),
            'uptime' => $uptime,
            'load_average' => $loadAverage,
            'cpu_usage' => $cpuUsage,
            'memory_usage' => $memoryUsage,
        ]);
    }

    /**
     * Get system uptime.
     */
    protected function getUptime(): ?string
    {
        if (File::exists('/proc/uptime')) {
            $uptime = trim(File::get('/proc/uptime'));
            $uptimeSeconds = explode(' ', $uptime)[0];

            $days = floor($uptimeSeconds / 86400);
            $hours = floor(($uptimeSeconds % 86400) / 3600);
            $minutes = floor(($uptimeSeconds % 3600) / 60);

            return "{$days}d {$hours}h {$minutes}m";
        }

        return null;
    }

    /**
     * Get system load average.
     */
    protected function getLoadAverage(): ?array
    {
        if (File::exists('/proc/loadavg')) {
            $load = explode(' ', trim(File::get('/proc/loadavg')));

            return [
                '1min' => (float) $load[0],
                '5min' => (float) $load[1],
                '15min' => (float) $load[2],
            ];
        }

        return null;
    }

    /**
     * Get CPU usage percentage.
     */
    protected function getCpuUsage(): ?array
    {
        if (File::exists('/proc/stat')) {
            $stat = File::get('/proc/stat');
            preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $matches);

            if ($matches) {
                $user = (int) $matches[1];
                $nice = (int) $matches[2];
                $system = (int) $matches[3];
                $idle = (int) $matches[4];
                $iowait = (int) $matches[5];
                $irq = (int) $matches[6];
                $softirq = (int) $matches[7];

                $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq;
                $used = $total - $idle - $iowait;

                if ($total > 0) {
                    return [
                        'used' => $used,
                        'total' => $total,
                        'percentage' => round(($used / $total) * 100, 1),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get memory usage.
     */
    protected function getMemoryUsage(): ?array
    {
        // Try using 'free -b' command first (more reliable in containers)
        $freeOutput = shell_exec('free -b 2>/dev/null');

        if ($freeOutput) {
            $lines = explode("\n", trim($freeOutput));

            // Parse "Mem:" line (format: total used free shared buff/cache available)
            if (isset($lines[1])) {
                $parts = preg_split('/\s+/', $lines[1]);

                if (count($parts) >= 3) {
                    $total = (int) $parts[1];
                    $used = (int) $parts[2];
                    $free = (int) $parts[3];

                    // If available is present (newer free versions), use it
                    if (count($parts) >= 7) {
                        $available = (int) $parts[6];
                        $used = $total - $available;
                    }

                    if ($total > 0) {
                        return [
                            'total' => $total,
                            'available' => $total - $used,
                            'used' => $used,
                            'percentage' => round(($used / $total) * 100, 1),
                        ];
                    }
                }
            }
        }

        // Fallback to /proc/meminfo parsing
        if (File::exists('/proc/meminfo')) {
            $meminfo = File::get('/proc/meminfo');

            // Try to match MemTotal and MemAvailable (modern kernels)
            preg_match('/^MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/^MemAvailable:\s+(\d+)/', $meminfo, $available);

            $totalKb = isset($total[1]) ? (int) $total[1] : 0;

            // If MemAvailable is not available or is 0, fall back to MemFree + Buffers + Cached (older kernels)
            if (empty($available) || (int) $available[1] === 0) {
                preg_match('/^MemFree:\s+(\d+)/', $meminfo, $free);
                preg_match('/^Buffers:\s+(\d+)/', $meminfo, $buffers);
                preg_match('/^Cached:\s+(\d+)/', $meminfo, $cached);

                $freeKb = isset($free[1]) ? (int) $free[1] : 0;
                $buffersKb = isset($buffers[1]) ? (int) $buffers[1] : 0;
                $cachedKb = isset($cached[1]) ? (int) $cached[1] : 0;
                $availableKb = $freeKb + $buffersKb + $cachedKb;
            } else {
                $availableKb = (int) $available[1];
            }

            if ($totalKb > 0) {
                $usedKb = $totalKb - $availableKb;

                return [
                    'total' => $totalKb * 1024,
                    'available' => $availableKb * 1024,
                    'used' => $usedKb * 1024,
                    'percentage' => round(($usedKb / $totalKb) * 100, 1),
                ];
            }
        }

        return null;
    }

    /**
     * Get network interfaces (physical only, excluding Docker).
     */
    public function networkInterfaces(): JsonResponse
    {
        $interfaces = [];

        // Get list of network interfaces using ip command
        $output = shell_exec('ip -br addr show 2>/dev/null');

        if (!$output) {
            return response()->json(['interfaces' => []]);
        }

        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            $name = $parts[0] ?? '';
            $state = $parts[1] ?? 'UNKNOWN';

            // Skip loopback, Docker interfaces, and virtual interfaces
            if ($name === 'lo' || str_starts_with($name, 'docker') || str_starts_with($name, 'veth') || str_starts_with($name, 'br-')) {
                continue;
            }

            // Skip if not a physical-looking interface (enp, ens, eth, etc.)
            if (!preg_match('/^(en|eth|ens|eno|enp)/', $name)) {
                continue;
            }

            // Get MAC address
            $mac = '';
            $macOutput = shell_exec("cat /sys/class/net/{$name}/address 2>/dev/null");
            if ($macOutput) {
                $mac = trim($macOutput);
            }

            // Get IPv4 and IPv6 addresses
            $ipv4 = '';
            $ipv6 = [];

            if (isset($parts[2])) {
                $ipParts = array_slice($parts, 2);
                foreach ($ipParts as $ip) {
                    if (str_contains($ip, ':')) {
                        // IPv6 - extract just the address part (without prefix)
                        $ipv6Addr = explode('/', $ip)[0];
                        if (!str_starts_with($ipv6Addr, 'fe80')) {
                            $ipv6[] = $ipv6Addr;
                        }
                    } else {
                        // IPv4
                        $ipv4 = explode('/', $ip)[0];
                    }
                }
            }

            // Get additional info from ip link show
            $linkInfo = shell_exec("ip link show {$name} 2>/dev/null");
            $mtu = '1500';
            if ($linkInfo && preg_match('/mtu (\d+)/', $linkInfo, $mtuMatch)) {
                $mtu = $mtuMatch[1];
            }

            $interfaces[] = [
                'name' => $name,
                'state' => $state,
                'mac' => $mac,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
                'mtu' => $mtu,
            ];
        }

        return response()->json(['interfaces' => $interfaces]);
    }

    /**
     * Get current network configuration for a specific interface.
     */
    public function getInterfaceConfig(string $interface): JsonResponse
    {
        $configFile = '/etc/network/interfaces.d/' . $interface;

        $method = 'dhcp'; // Default to DHCP
        $ip = '';
        $netmask = '';
        $gateway = '';

        // First check if there's a config file in interfaces.d
        if (File::exists($configFile)) {
            $content = File::get($configFile);
            $parsed = $this->parseInterfaceConfig($content, $interface);

            if ($parsed) {
                $method = $parsed['method'];
                $ip = $parsed['address'] ?? '';
                $netmask = $parsed['netmask'] ?? '';
                $gateway = $parsed['gateway'] ?? '';
            }
        }

        // If no config file found, check the main interfaces file
        if ($method === 'dhcp' && $ip === '') {
            $mainFile = '/etc/network/interfaces';
            if (File::exists($mainFile)) {
                $content = File::get($mainFile);
                $parsed = $this->parseInterfaceConfig($content, $interface);

                if ($parsed) {
                    $method = $parsed['method'];
                    $ip = $parsed['address'] ?? '';
                    $netmask = $parsed['netmask'] ?? '';
                    $gateway = $parsed['gateway'] ?? '';
                }
            }
        }

        return response()->json([
            'method' => $method,
            'ip' => $ip,
            'netmask' => $netmask,
            'gateway' => $gateway,
        ]);
    }

    /**
     * Parse interface configuration from ifupdown format.
     */
    protected function parseInterfaceConfig(string $content, string $interface): ?array
    {
        $lines = explode("\n", $content);
        $inInterface = false;
        $result = ['method' => 'dhcp'];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Check for interface declaration
            if (preg_match('/^iface\s+' . preg_quote($interface, '/') . '\s+inet\s+(\w+)/', $line, $matches)) {
                $inInterface = true;
                $result['method'] = $matches[1];
                continue;
            }

            // If we're in the interface block, parse its settings
            if ($inInterface) {
                // Check for address (IP)
                if (preg_match('/^address\s+(\S+)/', $line, $matches)) {
                    $result['address'] = $matches[1];
                }
                // Check for netmask
                elseif (preg_match('/^netmask\s+(\S+)/', $line, $matches)) {
                    $result['netmask'] = $matches[1];
                }
                // Check for gateway
                elseif (preg_match('/^gateway\s+(\S+)/', $line, $matches)) {
                    $result['gateway'] = $matches[1];
                }
                // Check for end of interface block
                elseif (preg_match('/^\S+/', $line)) {
                    // Next non-indented line marks end of this interface
                    $inInterface = false;
                }
            }
        }

        return $result;
    }

    /**
     * Get network configuration from ifupdown (Debian traditional).
     */
    public function getNetworkConfig(): JsonResponse
    {
        $config = [];

        // Read the main interfaces file
        $interfacesFile = '/etc/network/interfaces';
        if (File::exists($interfacesFile)) {
            $config['main'] = File::get($interfacesFile);
        }

        // Read interface-specific configs from interfaces.d
        $interfacesDir = '/etc/network/interfaces.d';
        if (File::exists($interfacesDir)) {
            $files = File::files($interfacesDir);
            foreach ($files as $file) {
                $config['interfaces'][$file->getFilename()] = File::get($file->getPathname());
            }
        }

        return response()->json(['config' => $config]);
    }

    /**
     * Apply network configuration via ifupdown.
     */
    public function setNetworkConfig(): JsonResponse
    {
        $request = request();
        $interface = $request->input('interface');
        $method = $request->input('method'); // 'dhcp' or 'static'
        $ip = $request->input('ip');
        $netmask = $request->input('netmask');
        $gateway = $request->input('gateway');

        if (empty($interface)) {
            return response()->json(['success' => false, 'error' => 'Interface name is required'], 422);
        }

        if ($method !== 'dhcp' && $method !== 'static') {
            return response()->json(['success' => false, 'error' => 'Method must be dhcp or static'], 422);
        }

        if ($method === 'static') {
            if (empty($ip) || empty($netmask) || empty($gateway)) {
                return response()->json(['success' => false, 'error' => 'IP, netmask, and gateway are required for static IP'], 422);
            }
        }

        // Validate IP address format
        if (!empty($ip) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return response()->json(['success' => false, 'error' => 'Invalid IP address format'], 422);
        }

        // Validate gateway format
        if (!empty($gateway) && !filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return response()->json(['success' => false, 'error' => 'Invalid gateway format'], 422);
        }

        // Build ifupdown configuration
        $interfaceConfig = $this->generateIfupdownConfig($interface, $method, $ip, $netmask, $gateway);

        // Write to interfaces.d directory
        $interfacesDir = '/etc/network/interfaces.d';
        if (!File::exists($interfacesDir)) {
            return response()->json(['success' => false, 'error' => 'Directory /etc/network/interfaces.d not found'], 500);
        }

        $targetFile = $interfacesDir . '/' . $interface;

        // Write configuration to temp file first
        $tempFile = '/tmp/interfaces-' . $interface;
        File::put($tempFile, $interfaceConfig);

        // Copy to interfaces.d with sudo
        $copyResult = shell_exec("sudo cp {$tempFile} {$targetFile} 2>&1");

        if ($copyResult !== null && str_contains($copyResult, 'error')) {
            return response()->json(['success' => false, 'error' => 'Failed to copy config: ' . $copyResult], 500);
        }

        // Bring interface down and up to apply changes
        // First, try to bring down if it's already up
        shell_exec("sudo ifdown {$interface} 2>/dev/null");

        // Bring interface up
        $upResult = shell_exec("sudo ifup {$interface} 2>&1");

        // Clean up temp file
        @unlink($tempFile);

        if ($upResult !== null && str_contains($upResult, 'error')) {
            return response()->json(['success' => false, 'error' => 'Failed to apply configuration: ' . $upResult], 500);
        }

        return response()->json(['success' => true, 'message' => 'Network configuration applied successfully']);
    }

    /**
     * Generate ifupdown configuration for an interface.
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
            $config .= "    gateway {$gateway}\n";
        }

        return $config;
    }
}
