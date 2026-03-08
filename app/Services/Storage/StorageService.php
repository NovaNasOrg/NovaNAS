<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;

/**
 * Storage service for managing disks and pools.
 *
 * This service provides methods to interact with system storage,
 * currently using lsblk for disk listing. Can be extended to support
 * ZFS, LVM, and other storage backends.
 */
class StorageService
{
    /**
     * List all available disks in the system using lsblk.
     *
     * @return array<int, array{
     *     name: string,
     *     type: string,
     *     size: int,
     *     vendor: string|null,
     *     model: string|null,
     *     serial: string|null,
     *     rotational: bool,
     *     readonly: bool,
     *     removable: bool
     * }>
     */
    public function listDisks(): array
    {
        $result = Process::run('lsblk -b -o NAME,TYPE,SIZE,VENDOR,MODEL,SERIAL,ROTA,RO,RM,MOUNTPOINT --json');

        if ($result->failed()) {
            return [];
        }

        $data = json_decode($result->output(), true);

        if (!$data || !isset($data['blockdevices'])) {
            return [];
        }

        // Get system disk info (disk containing root or boot)
        $systemDisk = $this->getSystemDisk();

        $disks = [];

        foreach ($data['blockdevices'] as $device) {
            // Only include physical disks (type: disk)
            if ($device['type'] !== 'disk') {
                continue;
            }

            $disks[] = [
                'name' => $device['name'],
                'type' => $device['type'],
                'size' => (int) ($device['size'] ?? 0),
                'vendor' => $device['vendor'] ?? null,
                'model' => $device['model'] ?? null,
                'serial' => $device['serial'] ?? null,
                'rotational' => (bool) ($device['rota'] ?? false),
                'readonly' => (bool) ($device['ro'] ?? false),
                'removable' => (bool) ($device['rm'] ?? false),
                'isSystem' => $this->isSystemDisk($device['name'], $systemDisk),
            ];
        }

        return $disks;
    }

    /**
     * Detect which disk is the system disk.
     */
    protected function getSystemDisk(): ?string
    {
        // Get the disk containing / (root filesystem)
        $result = Process::run('lsblk -no PKNAME /');
        if ($result->successful() && trim($result->output())) {
            return trim($result->output());
        }

        // Fallback: check /boot
        $result = Process::run('lsblk -no PKNAME /boot 2>/dev/null');
        if ($result->successful() && trim($result->output())) {
            return trim($result->output());
        }

        // Fallback: parse full lsblk output to find disk with / mountpoint
        $result = Process::run('lsblk -o NAME,TYPE,MOUNTPOINT --json');
        if ($result->successful()) {
            $data = json_decode($result->output(), true);
            if ($data && isset($data['blockdevices'])) {
                foreach ($data['blockdevices'] as $device) {
                    if (($device['type'] ?? '') === 'disk' && isset($device['children'])) {
                        foreach ($device['children'] as $partition) {
                            $mountpoint = $partition['mountpoint'] ?? null;
                            if ($mountpoint === '/' || $mountpoint === '/boot/efi' || $mountpoint === '/boot') {
                                return $device['name'];
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a specific disk is the system disk.
     */
    protected function isSystemDisk(string $diskName, ?string $systemDisk): bool
    {
        return $systemDisk === $diskName;
    }

    /**
     * Get the capacity information for a specific disk.
     *
     * @param string $device The device name (e.g., 'sda')
     * @return array{
     *     total: int,
     *     used: int,
     *     available: int,
     *     percentage: float
     * }|null
     */
    public function getCapacity(string $device): ?array
    {
        $result = Process::run("df -B1 /dev/{$device}");

        if ($result->failed()) {
            return null;
        }

        $lines = explode("\n", trim($result->output()));

        // Skip header line
        if (count($lines) < 2) {
            return null;
        }

        $parts = preg_split('/\s+/', $lines[1]);

        if (count($parts) < 4) {
            return null;
        }

        $total = (int) $parts[1];
        $used = (int) $parts[2];
        $available = (int) $parts[3];

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    /**
     * List all ZFS pools (if ZFS is available).
     *
     * @return array<int, array{
     *     name: string,
     *     size: int,
     *     allocated: int,
     *     free: int,
     *     health: string,
     *     mountpoint: string|null
     * }>
     */
    public function listPools(): array
    {
        // Check if zfs command is available
        $checkResult = Process::run('which zfs');

        if ($checkResult->failed()) {
            return [];
        }

        $result = Process::run('zpool list -Hp -o name,size,allocated,free,health,altroot');

        if ($result->failed()) {
            return [];
        }

        $pools = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if (count($parts) >= 5) {
                $pools[] = [
                    'name' => $parts[0],
                    'size' => (int) $parts[1],
                    'allocated' => (int) $parts[2],
                    'free' => (int) $parts[3],
                    'health' => $parts[4],
                    'mountpoint' => $parts[5] === '-' ? null : $parts[5],
                ];
            }
        }

        return $pools;
    }

    /**
     * Get detailed information about a specific ZFS pool.
     *
     * @param string $pool The pool name
     * @return array|null
     */
    public function getPoolInfo(string $pool): ?array
    {
        $result = Process::run("zpool list -Hp -o name,size,allocated,free,health,cap,altroot,allocated {$pool}");

        if ($result->failed()) {
            return null;
        }

        $lines = explode("\n", trim($result->output()));

        if (empty($lines[0])) {
            return null;
        }

        $parts = preg_split('/\s+/', $lines[0]);

        if (count($parts) < 6) {
            return null;
        }

        return [
            'name' => $parts[0],
            'size' => (int) $parts[1],
            'allocated' => (int) $parts[2],
            'free' => (int) $parts[3],
            'health' => $parts[4],
            'capacity' => (int) $parts[5],
            'mountpoint' => $parts[6] === '-' ? null : $parts[6],
        ];
    }
}
