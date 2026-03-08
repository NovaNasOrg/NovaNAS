<?php

namespace App\Contracts;

/**
 * Interface for storage backend implementations.
 *
 * This interface defines the contract that storage backends (ZFS, LVM, etc.)
 * must implement. The StorageService will use these methods to interact
 * with different storage systems.
 */
interface StorageInterface
{
    /**
     * List all available disks in the system.
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
    public function listDisks(): array;

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
    public function getCapacity(string $device): ?array;

    /**
     * List all storage pools.
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
     * Get detailed information about a specific pool.
     *
     * @param string $pool The pool name
     * @return array|null
     */
    public function getPoolInfo(string $pool): ?array;
}
