<?php

namespace App\Services\Storage;

use App\Contracts\StorageInterface;
use Illuminate\Support\Facades\Process;

/**
 * Storage service for managing disks and coordinating storage backends.
 *
 * This service provides general, filesystem-independent operations for
 * listing physical disks and coordinating between different storage backends
 * like ZFS, EXT4, LVM, etc.
 *
 * Each filesystem type has its own implementation class that implements
 * StorageInterface (e.g., ZfsStorage, Ext4Storage).
 */
class StorageService
{
    /**
     * @var array<string, StorageInterface>
     */
    protected array $backends = [];

    /**
     * Create a new StorageService instance.
     */
    public function __construct()
    {
        // Register available storage backends
        $this->registerBackend('zfs', new ZfsStorage());
    }

    /**
     * Register a storage backend.
     */
    public function registerBackend(string $name, StorageInterface $backend): self
    {
        $this->backends[$name] = $backend;

        return $this;
    }

    /**
     * Get a storage backend by name.
     */
    public function backend(string $name): ?StorageInterface
    {
        return $this->backends[$name] ?? null;
    }

    /**
     * Get all available storage backends.
     *
     * @return array<string, StorageInterface>
     */
    public function getBackends(): array
    {
        return $this->backends;
    }

    /**
     * Get the default pool backend (ZFS if available).
     */
    public function getPoolBackend(): ?StorageInterface
    {
        // Prefer ZFS if available
        if (isset($this->backends['zfs']) && $this->backends['zfs']->isAvailable()) {
            return $this->backends['zfs'];
        }

        // Fallback to first available backend
        foreach ($this->backends as $backend) {
            if ($backend->isAvailable()) {
                return $backend;
            }
        }

        return null;
    }

    /**
     * List all available storage backends that are available on the system.
     *
     * @return array<string, bool>
     */
    public function getAvailableBackends(): array
    {
        $available = [];

        foreach ($this->backends as $name => $backend) {
            $available[$name] = $backend->isAvailable();
        }

        return $available;
    }

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
     * List all storage pools using the default pool backend.
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
        $backend = $this->getPoolBackend();

        if ($backend === null) {
            return [];
        }

        return $backend->listPools();
    }

    /**
     * Get detailed information about a specific pool.
     *
     * @param string $pool The pool name
     * @return array|null
     */
    public function getPoolInfo(string $pool): ?array
    {
        $backend = $this->getPoolBackend();

        if ($backend === null) {
            return null;
        }

        return $backend->getPoolInfo($pool);
    }

    /**
     * Check if ZFS is available on the system.
     */
    public function isZfsAvailable(): bool
    {
        return isset($this->backends['zfs']) && $this->backends['zfs']->isAvailable();
    }

    /**
     * Get ZFS-specific operations (if ZFS is available).
     */
    public function zfs(): ?ZfsStorage
    {
        if (isset($this->backends['zfs']) && $this->backends['zfs']->isAvailable()) {
            return $this->backends['zfs'];
        }

        return null;
    }
}
