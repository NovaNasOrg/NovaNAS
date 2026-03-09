<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

/**
 * SMART service for disk health monitoring using smartmontools.
 *
 * This service provides methods to interact with SMART data on disks
 * using the smartctl command from smartmontools package.
 */
class SmartService
{
    /**
     * Get the SMART health status of a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     passed: bool,
     *     status: string,
     *     message: string
     * }|null
     */
    public function getHealthStatus(string $device): ?array
    {
        $result = Process::run('sudo smartctl -H /dev/'.$device);

        if ($result->failed()) {
            Log::warning('SMART health check failed for /dev/'.$device.': '.$result->errorOutput);

            return null;
        }

        $output = $result->output();
        $passed = stripos($output, 'PASSED') !== false || stripos($output, 'OK') !== false;
        $status = $passed ? 'Healthy' : 'Failed';

        return [
            'passed' => $passed,
            'status' => $status,
            'message' => $this->extractHealthMessage($output),
        ];
    }

    /**
     * Get the SMART test results (selftest log) for a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array<int, array{
     *     id: int,
     *     test_type: string,
     *     status: string,
     *     remaining: int,
     *     lifetime: int,
     *     lba_of_first_error: string|null
     * }>|null
     */
    public function getTestResults(string $device): ?array
    {
        $result = Process::run('sudo smartctl -l selftest /dev/'.$device);

        if ($result->failed()) {
            Log::warning('SMART selftest log failed for /dev/'.$device.': '.$result->errorOutput);

            return null;
        }

        return $this->parseSelftestLog($result->output());
    }

    /**
     * Check if a SMART test is currently running on a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return bool
     */
    public function isTestRunning(string $device): bool
    {
        $result = Process::run('sudo smartctl -c /dev/'.$device);

        if ($result->failed()) {
            return false;
        }

        $output = $result->output();

        // Check if a test is in progress
        return stripos($output, 'Self-test in progress') !== false;
    }

    /**
     * Start a SMART short test on a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return bool
     */
    public function startShortTest(string $device): bool
    {
        $result = Process::run('sudo smartctl -t short /dev/'.$device);

        if ($result->failed()) {
            Log::error('Failed to start SMART short test on /dev/'.$device.': '.$result->errorOutput);

            return false;
        }

        Log::info("Started SMART short test on /dev/{$device}");

        return true;
    }

    /**
     * Start a SMART long test on a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return bool
     */
    public function startLongTest(string $device): bool
    {
        $result = Process::run('sudo smartctl -t long /dev/'.$device);

        if ($result->failed()) {
            Log::error('Failed to start SMART long test on /dev/'.$device.': '.$result->errorOutput);

            return false;
        }

        Log::info("Started SMART long test on /dev/{$device}");

        return true;
    }

    /**
     * Get SMART capabilities for a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     smart_supported: bool,
     *     smart_enabled: bool,
     *     is_nvme: bool,
     *     test_types: array{short: bool, long: bool, conveyance: bool}
     * }|null
     */
    public function getCapabilities(string $device): ?array
    {
        $result = Process::run('sudo smartctl -i /dev/'.$device);

        if ($result->failed()) {
            return null;
        }

        $output = $result->output();

        return [
            'smart_supported' => stripos($output, 'SMART support is: Enabled') !== false,
            'smart_enabled' => stripos($output, 'SMART support is: Enabled') !== false,
            'is_nvme' => stripos($output, 'NVMe') !== false,
            'test_types' => [
                'short' => stripos($output, 'Short self-test routine') !== false,
                'long' => stripos($output, 'Extended self-test routine') !== false,
                'conveyance' => stripos($output, 'Conveyance self-test routine') !== false,
            ],
        ];
    }

    /**
     * Run SMART tests on all available disks.
     *
     * @param string $testType 'short' or 'long'
     * @return array<string, bool>
     */
    public function runTestsOnAllDisks(string $testType = 'short'): array
    {
        $storageService = new StorageService();
        $disks = $storageService->listDisks();
        $results = [];

        foreach ($disks as $disk) {
            // Skip system disk
            if ($disk['isSystem']) {
                continue;
            }

            // Check if SMART is supported
            $capabilities = $this->getCapabilities($disk['name']);
            if (!$capabilities || !$capabilities['smart_supported']) {
                $results[$disk['name']] = false;
                continue;
            }

            // Run the test
            $results[$disk['name']] = $testType === 'long'
                ? $this->startLongTest($disk['name'])
                : $this->startShortTest($disk['name']);
        }

        return $results;
    }

    /**
     * Get SMART status for all available disks.
     *
     * @return array<int, array{
     *     name: string,
     *     health: array|null,
     *     capabilities: array|null,
     *     is_test_running: bool
     * }>
     */
    public function getAllDisksHealth(): array
    {
        $storageService = new StorageService();
        $disks = $storageService->listDisks();
        $results = [];

        foreach ($disks as $disk) {
            $health = $this->getHealthStatus($disk['name']);
            $capabilities = $this->getCapabilities($disk['name']);
            $lastTest = $this->getLastTestTime($disk['name']);
            $nextTest = $this->getNextTestTime($disk['name']);
            $isTestRunning = $this->isTestRunning($disk['name']);

            $results[] = [
                'name' => $disk['name'],
                'model' => $disk['model'],
                'size' => $disk['size'],
                'isSystem' => $disk['isSystem'],
                'health' => $health,
                'capabilities' => $capabilities,
                'last_test' => $lastTest,
                'next_test' => $nextTest,
                'is_test_running' => $isTestRunning,
            ];
        }

        return $results;
    }

    /**
     * Get detailed SMART information for a disk using smartctl -ax.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     device_info: array{
     *         model: string,
     *         serial: string,
     *         firmware: string,
     *         capacity: string,
     *         type: string
     *     },
     *     health: array{
     *         passed: bool,
     *         status: string
     *     },
     *     smart_support: array{
     *         available: bool,
     *         enabled: bool
     *     },
     *     attributes: array<int, array{
     *         id: int,
     *         name: string,
     *         value: int,
     *         worst: int,
     *         threshold: int,
     *         raw_value: int
     *     }>,
     *     last_test: array{
     *         type: string,
     *         status: string,
     *         remaining: int,
     *         lifetime_hours: int
     *     }|null
     * }|null
     */
    public function getDetailedInfo(string $device): ?array
    {
        $result = Process::run('sudo smartctl -ax /dev/'.$device);

        if ($result->failed()) {
            Log::warning('SMART detailed info failed for /dev/'.$device.': '.$result->errorOutput);

            return null;
        }

        $output = $result->output();

        // Extract device information
        $deviceInfo = $this->parseDeviceInfo($output);

        // Extract health status
        $healthPassed = stripos($output, 'PASSED') !== false || stripos($output, 'OK') !== false;

        // Extract SMART support status
        $smartAvailable = stripos($output, 'SMART support is: Available') !== false;
        $smartEnabled = stripos($output, 'SMART support is: Enabled') !== false;

        // Extract SMART attributes
        $attributes = $this->parseAttributes($output);

        // Extract last test result
        $lastTest = $this->parseLastTest($output);

        // Extract next test time (7 days after last test)
        $nextTest = $this->getNextTestTime($device);

        return [
            'device_info' => $deviceInfo,
            'health' => [
                'passed' => $healthPassed,
                'status' => $healthPassed ? 'PASSED' : 'FAILED',
            ],
            'smart_support' => [
                'available' => $smartAvailable,
                'enabled' => $smartEnabled,
            ],
            'attributes' => $attributes,
            'last_test' => $lastTest,
            'next_test' => $nextTest,
        ];
    }

    /**
     * Get the last SMART test time for a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     hours_ago: int,
     *     timestamp: string
     * }|null
     */
    public function getLastTestTime(string $device): ?array
    {
        // Get current Power_On_Hours
        $powerResult = Process::run('sudo smartctl -A /dev/'.$device.' | grep "Power_On_Hours"');

        if ($powerResult->failed()) {
            return null;
        }

        // Parse current power on hours (e.g., "  9 Power_On_Hours          0x0032   096   096   000    Old_age   Always       -       15189")
        if (!preg_match('/(\d+)\s*$/', $powerResult->output(), $powerMatches)) {
            return null;
        }

        $currentPowerOnHours = (int) $powerMatches[1];

        // Get selftest log
        $selftestResult = Process::run('sudo smartctl -l selftest /dev/'.$device);

        if ($selftestResult->failed()) {
            return null;
        }

        $output = $selftestResult->output();

        // Parse the first line after the header that contains test data
        // Look for pattern: # 1  Short offline       Completed without error       00%     63972         -
        if (preg_match('/#\s*1\s+\S+\s+\S+\s+(\S.*?)\s+(\d+)%\s+(\d+)/', $output, $matches)) {
            $testLifetimeHours = (int) $matches[3];

            // Calculate how many hours ago the test was run
            $hoursAgo = $currentPowerOnHours - $testLifetimeHours;
            $timestamp = now()->subHours($hoursAgo);

            return [
                'hours_ago' => $hoursAgo,
                'timestamp' => $timestamp->toIso8601String(),
                'timestamp_human' => $timestamp->diffForHumans(),
            ];
        }

        return null;
    }

    /**
     * Get the next scheduled SMART test time for a disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     days_until: int,
     *     hours_until: int,
     *     timestamp: string,
     *     timestamp_human: string
     * }|null
     */
    public function getNextTestTime(string $device): ?array
    {
        $lastTest = $this->getLastTestTime($device);

        if (!$lastTest) {
            return null;
        }

        // Next test is 7 days after the last test
        $lastTestTime = \Carbon\Carbon::parse($lastTest['timestamp']);
        $nextTestTime = $lastTestTime->addDays(7);

        $daysUntil = now()->diffInDays($nextTestTime, false);
        $hoursUntil = now()->diffInHours($nextTestTime, false);

        return [
            'days_until' => max(0, $daysUntil),
            'hours_until' => max(0, $hoursUntil),
            'timestamp' => $nextTestTime->toIso8601String(),
            'timestamp_human' => $nextTestTime->diffForHumans(),
        ];
    }

    /**
     * Parse device information from smartctl output.
     *
     * @return array{
     *     model: string,
     *     serial: string,
     *     firmware: string,
     *     capacity: string,
     *     type: string
     * }
     */
    protected function parseDeviceInfo(string $output): array
    {
        $info = [
            'model' => 'Unknown',
            'serial' => 'Unknown',
            'firmware' => 'Unknown',
            'capacity' => 'Unknown',
            'type' => 'Unknown',
        ];

        // Model Family / Device Model
        if (preg_match('/Model Family:\s*(.+)$/m', $output, $matches)) {
            $info['model'] = trim($matches[1]);
        } elseif (preg_match('/Device Model:\s*(.+)$/m', $output, $matches)) {
            $info['model'] = trim($matches[1]);
        }

        // Serial Number
        if (preg_match('/Serial Number:\s*(.+)$/m', $output, $matches)) {
            $info['serial'] = trim($matches[1]);
        }

        // Firmware Version
        if (preg_match('/Firmware Version:\s*(.+)$/m', $output, $matches)) {
            $info['firmware'] = trim($matches[1]);
        }

        // User Capacity
        if (preg_match('/User Capacity:\s*(.+?)\s*bytes/m', $output, $matches)) {
            $info['capacity'] = trim($matches[1]).' bytes';
        }

        // Rotation Rate / Form Factor (for SSD/HDD detection)
        if (preg_match('/Rotation Rate:\s*(.+)$/m', $output, $matches)) {
            $info['type'] = trim($matches[1]);
        } elseif (preg_match('/Form Factor:\s*(.+)$/m', $output, $matches)) {
            $info['type'] = trim($matches[1]);
        }

        return $info;
    }

    /**
     * Parse SMART attributes from smartctl output.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     value: int,
     *     worst: int,
     *     threshold: int,
     *     raw_value: int
     * }>
     */
    protected function parseAttributes(string $output): array
    {
        $attributes = [];
        $lines = explode("\n", $output);

        $inAttributesSection = false;

        foreach ($lines as $line) {
            // Start of SMART Attributes section
            if (preg_match('/ID# ATTRIBUTE_NAME/', $line)) {
                $inAttributesSection = true;
                continue;
            }

            // End of SMART Attributes section (look for footer note lines)
            if ($inAttributesSection && preg_match('/^\s*\|\|\|\|_/', $line)) {
                break;
            }

            // Skip empty lines
            if ($inAttributesSection && preg_match('/^\s*$/', $line)) {
                continue;
            }

            // Parse by splitting on whitespace
            // Format: ID NAME FLAGS VALUE WORST THRESH FAIL RAW_VALUE
            // Example: 5 Reallocated_Sector_Ct PO--CK 100 100 010 - 0
            $parts = preg_split('/\s+/', trim($line));

            // We need at least 8 parts: id, name, flags, value, worst, threshold, fail, raw_value
            if ($inAttributesSection && count($parts) >= 8 && is_numeric($parts[0])) {
                $attributes[] = [
                    'id' => (int) $parts[0],
                    'name' => $parts[1],
                    'value' => (int) $parts[3],
                    'worst' => (int) $parts[4],
                    'threshold' => (int) $parts[5],
                    'raw_value' => (int) $parts[7],
                ];
            }
        }

        return $attributes;
    }

    /**
     * Parse last test result from smartctl output.
     *
     * @return array{
     *     type: string,
     *     status: string,
     *     remaining: int,
     *     lifetime_hours: int
     * }|null
     */
    protected function parseLastTest(string $output): ?array
    {
        // Look for the first test entry in the self-test log
        // # 1  Short offline       Completed without error       00%     15189         -
        if (preg_match('/#\s*1\s+(\S+\s+\S+)\s+(.+?)\s+(\d+)%\s+(\d+)\s+/', $output, $matches)) {
            return [
                'type' => trim($matches[1]),
                'status' => trim($matches[2]),
                'remaining' => (int) $matches[3],
                'lifetime_hours' => (int) $matches[4],
            ];
        }

        return null;
    }

    /**
     * Parse power on hours from smartctl output.
     */
    protected function parsePowerOnHours(string $output): ?int
    {
        return null;
    }

    /**
     * Parse temperature from smartctl output.
     */
    protected function parseTemperature(string $output): ?int
    {
        return null;
    }

    /**
     * Extract health message from smartctl output.
     */
    protected function extractHealthMessage(string $output): string
    {
        // Look for the overall health result
        if (preg_match('/SMART overall-health self-assessment test result:\s*(\S.*)$/m', $output, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: look for any PASSED/FAILED status
        if (preg_match('/(PASSED|FAILED)/i', $output, $matches)) {
            return $matches[1];
        }

        return 'Unknown';
    }

    /**
     * Parse SMART selftest log.
     *
     * @return array<int, array{
     *     id: int,
     *     test_type: string,
     *     status: string,
     *     remaining: int,
     *     lifetime: int,
     *     lba_of_first_error: string|null
     * }>
     */
    protected function parseSelftestLog(string $output): array
    {
        $results = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Match lines like: # 1  Short offline       Completed without error       00%     1234 -
            if (preg_match('/#\s*(\d+)\s+(\S+)\s+(\S+)\s+(\S.*?)\s+(\d+)%\s+(\d+)\s+(.+)/', $line, $matches)) {
                $results[] = [
                    'id' => (int) $matches[1],
                    'test_type' => $matches[2],
                    'status' => $matches[4],
                    'remaining' => (int) $matches[5],
                    'lifetime' => (int) $matches[6],
                    'lba_of_first_error' => trim($matches[7]) === '-' ? null : trim($matches[7]),
                ];
            }
        }

        return $results;
    }
}
