<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Storage\StorageService;
use Illuminate\Support\Facades\Process;

/**
 * Settings Service
 *
 * Manages key-value settings stored in the database and provides
 * utility methods for directory listing in storage pools.
 */
class SettingsService
{
    /**
     * Get a setting value by key.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return Setting::getValue($key, $default);
    }

    /**
     * Set a setting value by key.
     */
    public function set(string $key, ?string $value = null): Setting
    {
        return Setting::setValue($key, $value);
    }

    /**
     * Get multiple settings by keys.
     *
     * @param array<string> $keys
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        return Setting::getMultiple($keys);
    }

    /**
     * List directories in a storage pool's mountpoint.
     *
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     isDirectory: bool
     * }>
     */
    public function listDirectoriesInPool(string $poolName): array
    {
        $storageService = new StorageService();
        $pools = $storageService->listPools();

        $pool = collect($pools)->firstWhere('name', $poolName);

        if (!$pool || !$pool['mountpoint']) {
            return [];
        }

        return $this->listDirectories($pool['mountpoint']);
    }

    /**
     * List directories in a given path.
     *
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     isDirectory: bool
     * }>
     */
    public function listDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $items = [];

        $handle = opendir($path);
        if (!$handle) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            // Skip hidden files and current/parent directories
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . '/' . $entry;
            $isDirectory = is_dir($fullPath);

            $items[] = [
                'name' => $entry,
                'path' => $fullPath,
                'isDirectory' => $isDirectory,
            ];
        }

        closedir($handle);

        // Sort: directories first, then alphabetically
        usort($items, function ($a, $b) {
            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $a['isDirectory'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * Check if a path is within a storage pool.
     */
    public function isPathInPool(string $path, string $poolName): bool
    {
        $storageService = new StorageService();
        $pools = $storageService->listPools();
        $pool = collect($pools)->firstWhere('name', $poolName);

        if (!$pool || !$pool['mountpoint']) {
            return false;
        }

        return str_starts_with($path, $pool['mountpoint']);
    }
}
