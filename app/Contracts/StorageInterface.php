<?php

namespace App\Contracts;

/**
 * Interface for storage backend implementations.
 *
 * This interface defines the contract that storage backends (ZFS, EXT4, etc.)
 * must implement. Each filesystem type has its own implementation class.
 *
 * Note: General storage operations (like listing physical disks) are handled
 * by StorageService directly, not through this interface.
 */
interface StorageInterface
{
    /**
     * Check if the storage backend is available on the system.
     */
    public function isAvailable(): bool;

    /**
     * List all storage pools/volumes managed by this backend.
     *
     * For ZFS, this returns pools.
     * For EXT4, this would return mounted filesystems.
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
    public function listPools(): array;

    /**
     * Get detailed information about a specific pool/volume.
     *
     * @param string $pool The pool/volume name
     * @return array|null
     */
    public function getPoolInfo(string $pool): ?array;

    /**
     * Get the mountpoint for a storage pool or dataset.
     */
    public function getMountpoint(string $poolOrDataset): ?string;

    /**
     * Get the health status of a pool.
     */
    public function getHealth(string $pool): ?string;

    /**
     * Get properties for a pool or dataset.
     *
     * @return array<string, string>
     */
    public function getProperties(string $poolOrDataset): array;
}
