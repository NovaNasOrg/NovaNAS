<?php

namespace App\Services\Storage;

use App\Contracts\StorageInterface;
use Illuminate\Support\Facades\Process;

/**
 * ZFS storage backend implementation.
 *
 * This class handles all ZFS-specific storage operations including
 * pool management, filesystem operations, and property queries.
 */
class ZfsStorage implements StorageInterface
{
    /**
     * Check if ZFS is available on the system.
     */
    public function isAvailable(): bool
    {
        $result = Process::run('which zfs');

        return $result->successful();
    }

    /**
     * List all ZFS pools.
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
        if (!$this->isAvailable()) {
            return [];
        }

        $result = Process::run('zpool list -Hp -o name,size,allocated,free,health');

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
                $poolName = $parts[0];
                $mountpoint = $this->getMountpoint($poolName);

                $pools[] = [
                    'name' => $poolName,
                    'size' => (int) $parts[1],
                    'allocated' => (int) $parts[2],
                    'free' => (int) $parts[3],
                    'health' => $parts[4],
                    'mountpoint' => $mountpoint,
                ];
            }
        }

        return $pools;
    }

    /**
     * Get the mountpoint for a ZFS pool or dataset.
     */
    public function getMountpoint(string $poolOrDataset): ?string
    {
        $result = Process::run("zfs get -Hp -o value mountpoint {$poolOrDataset}");

        if ($result->failed()) {
            return null;
        }

        $mountpoint = trim($result->output());

        // 'none' means the pool is not mounted
        if ($mountpoint === 'none' || empty($mountpoint)) {
            return null;
        }

        return $mountpoint;
    }

    /**
     * Get detailed information about a specific ZFS pool.
     *
     * @param string $pool The pool name
     * @return array|null
     */
    public function getPoolInfo(string $pool): ?array
    {
        $result = Process::run("zpool list -Hp -o name,size,allocated,free,health,cap,altroot {$pool}");

        if ($result->failed()) {
            return null;
        }

        $lines = explode("\n", trim($result->output()));

        if (empty($lines[0])) {
            return null;
        }

        $parts = preg_split('/\s+/', $lines[0]);

        if (count($parts) < 5) {
            return null;
        }

        $mountpoint = $this->getMountpoint($pool);

        return [
            'name' => $parts[0],
            'size' => (int) $parts[1],
            'allocated' => (int) $parts[2],
            'free' => (int) $parts[3],
            'health' => $parts[4],
            'capacity' => isset($parts[5]) ? (int) $parts[5] : 0,
            'altroot' => isset($parts[6]) && $parts[6] !== '-' ? $parts[6] : null,
            'mountpoint' => $mountpoint,
        ];
    }

    /**
     * Get all ZFS datasets within a pool.
     *
     * @return array<int, array{
     *     name: string,
     *     used: int,
     *     available: int,
     *     refer: int,
     *     mountpoint: string|null,
     *     compression: string,
     *     checksum: string
     * }>
     */
    public function listDatasets(string $pool): array
    {
        $result = Process::run("zfs list -Hp -r -o name,used,available,refer,mountpoint,compression,checksum {$pool}");

        if ($result->failed()) {
            return [];
        }

        $datasets = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            // Skip the pool itself (datasets are children)
            if (count($parts) >= 5 && $parts[0] !== $pool) {
                $datasets[] = [
                    'name' => $parts[0],
                    'used' => (int) $parts[1],
                    'available' => (int) $parts[2],
                    'refer' => (int) $parts[3],
                    'mountpoint' => $parts[4] !== '-' ? $parts[4] : null,
                    'compression' => $parts[5] ?? 'off',
                    'checksum' => $parts[6] ?? 'fletcher4',
                ];
            }
        }

        return $datasets;
    }

    /**
     * Get the health status of a pool.
     */
    public function getHealth(string $pool): ?string
    {
        $result = Process::run("zpool list -Hp -o health {$pool}");

        if ($result->failed()) {
            return null;
        }

        return trim($result->output()) ?: null;
    }

    /**
     * Get ZFS properties for a pool or dataset.
     *
     * @return array<string, string>
     */
    public function getProperties(string $poolOrDataset): array
    {
        $result = Process::run("zfs get all -Hp {$poolOrDataset}");

        if ($result->failed()) {
            return [];
        }

        $properties = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 3);

            if (count($parts) >= 3) {
                $properties[$parts[1]] = $parts[2];
            }
        }

        return $properties;
    }

    /**
     * Get I/O statistics for a pool.
     *
     * @return array{
     *     readOps: int,
     *     writeOps: int,
     *     readBytes: int,
     *     writeBytes: int
     * }|null
     */
    public function getIoStats(string $pool): ?array
    {
        $result = Process::run("zpool iostat -Hp {$pool} 1");

        if ($result->failed()) {
            return null;
        }

        $lines = explode("\n", trim($result->output()));

        // Skip header line, get data line
        if (count($lines) < 2) {
            return null;
        }

        $parts = preg_split('/\s+/', $lines[1]);

        if (count($parts) < 5) {
            return null;
        }

        return [
            'readOps' => (int) $parts[1],
            'writeOps' => (int) $parts[2],
            'readBytes' => (int) $parts[3],
            'writeBytes' => (int) $parts[4],
        ];
    }
}
