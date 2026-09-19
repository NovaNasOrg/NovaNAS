<?php

namespace App\Http\Controllers;

use App\Services\AclService;
use App\Services\DesktopAccessService;
use App\Services\LinuxUserService;
use App\Services\NetworkService;
use App\Services\SambaService;
use App\Services\SettingsService;
use App\Services\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageController extends Controller
{
    public function __construct(
        protected StorageService $storageService,
        protected SettingsService $settingsService,
        protected SambaService $sambaService,
        protected LinuxUserService $linuxUserService,
        protected NetworkService $networkService,
        protected AclService $aclService,
        protected DesktopAccessService $desktopAccessService,
    ) {}

    /**
     * List all disks in the system.
     */
    public function disks(): JsonResponse
    {
        $disks = $this->storageService->listDisks();

        return response()->json([
            'disks' => $disks,
        ]);
    }

    /**
     * Get capacity information for a specific disk.
     */
    public function capacity(string $device): JsonResponse
    {
        $capacity = $this->storageService->getCapacity($device);

        if ($capacity === null) {
            return response()->json([
                'error' => 'Unable to get capacity for device',
            ], 404);
        }

        return response()->json($capacity);
    }

    /**
     * Get available storage backends and their availability.
     */
    public function backends(): JsonResponse
    {
        $backends = $this->storageService->getAvailableBackends();

        return response()->json([
            'backends' => $backends,
        ]);
    }

    /**
     * Validate a ZFS pool name for uniqueness and naming rules.
     */
    public function validatePoolName(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = $validated['name'];

        // ZFS pool naming rules:
        // - Must start with a letter
        // - Can only contain alphanumeric characters, underscore, hyphen, and colon
        // - Cannot exceed 255 characters
        // - Cannot be "log", "mirror", "raidz", "raidz2", "raidz3", "spare", or "special" (reserved names)
        // - Cannot contain consecutive slashes or start/end with slash
        // - Each component (separated by slash) must follow the same rules
        $reservedNames = ['log', 'mirror', 'raidz', 'raidz2', 'raidz3', 'spare', 'special', 'dedup', 'cache'];

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_\-:]*$/', $name)) {
            return response()->json([
                'valid' => false,
                'error' => 'Pool name must start with a letter and can only contain letters, numbers, underscores, hyphens, and colons.',
            ], 422);
        }

        if (in_array(strtolower($name), $reservedNames, true)) {
            return response()->json([
                'valid' => false,
                'error' => "The name '{$name}' is reserved by ZFS and cannot be used.",
            ], 422);
        }

        // Check uniqueness against existing ZFS pools
        $existingPools = $this->storageService->listAllPools();

        foreach ($existingPools as $type => $typePools) {
            foreach ($typePools as $pool) {
                if (strtolower($pool['name']) === strtolower($name)) {
                    return response()->json([
                        'valid' => false,
                        'error' => "A pool named '{$name}' already exists. Please choose a different name.",
                    ], 422);
                }
            }
        }

        return response()->json([
            'valid' => true,
        ]);
    }

    /**
     * Create a new storage pool.
     */
    public function createPool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:zfs,ext4',
            'name' => 'required_if:type,zfs|nullable|string|max:255|regex:/^[a-zA-Z0-9_-]+$/',
            'disks' => 'required|array|min:1',
            'disks.*' => 'string',
            'vdev_type' => 'required_if:type,zfs|nullable|string|in:stripe,mirror,raidz,raidz2',
            'mountpoint' => 'required|string|max:4096',
            'device' => 'required_if:type,ext4|nullable|string',
            'persist_fstab' => 'nullable|boolean',
        ]);

        $config = [
            'name' => $validated['name'] ?? null,
            'disks' => $validated['disks'],
            'vdev_type' => $validated['vdev_type'] ?? 'stripe',
            'mountpoint' => $validated['mountpoint'],
            'device' => $validated['device'] ?? null,
            'persist_fstab' => $validated['persist_fstab'] ?? true,
        ];

        // For EXT4, the single selected disk becomes the device
        if ($validated['type'] === 'ext4') {
            $config['device'] = $validated['device'] ?? ($validated['disks'][0] ?? '');
        }

        try {
            $result = $this->storageService->createPool($validated['type'], $config);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List all storage pools from all backends (ZFS, EXT4, etc.).
     */
    public function pools(): JsonResponse
    {
        $allPools = $this->storageService->listAllPools();

        $pools = [];
        $usedDisks = [];

        foreach ($allPools as $type => $typePools) {
            foreach ($typePools as $pool) {
                $pools[] = array_merge($pool, ['type' => $type]);

                // Track disks used by existing pools
                if ($type === 'ext4' && isset($pool['device'])) {
                    $deviceName = str_replace('/dev/', '', $pool['device']);
                    $usedDisks[] = $deviceName;
                }

                if ($type === 'zfs') {
                    $zfsUsed = $this->storageService->zfs()?->getPoolDisks($pool['name']);
                    if ($zfsUsed) {
                        $usedDisks = array_merge($usedDisks, $zfsUsed);
                    }
                }
            }
        }

        return response()->json([
            'pools' => $pools,
            'used_disks' => array_unique($usedDisks),
        ]);
    }

    /**
     * Get detailed information about a specific pool.
     */
    public function pool(string $pool): JsonResponse
    {
        $info = $this->storageService->getPoolInfo($pool);

        if ($info === null) {
            return response()->json([
                'error' => 'Pool not found',
            ], 404);
        }

        return response()->json($info);
    }

    /**
     * Unmount a pool and remove its /etc/fstab automount entry.
     */
    public function unmountPool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:zfs,ext4',
            'pool' => 'required|string',
        ]);

        try {
            $result = $this->storageService->unmountPool($validated['type'], $validated['pool']);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Permanently delete a pool (data loss).
     */
    public function deletePool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:zfs,ext4',
            'pool' => 'required|string',
        ]);

        try {
            $result = $this->storageService->deletePool($validated['type'], $validated['pool']);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mount a pool or volume at a specified mountpoint.
     */
    public function mountPool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:zfs,ext4',
            'pool' => 'required|string',
            'mountpoint' => 'required|string|max:4096',
        ]);

        try {
            $result = $this->storageService->mountPool($validated['type'], $validated['pool'], $validated['mountpoint']);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get settings by keys.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $keys = $request->input('keys', []);

        if (empty($keys)) {
            $keys = ['storage.user_files_home', 'storage.app_folders_home'];
        }

        $settings = $this->settingsService->getMultiple($keys);

        return response()->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Update settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        return response()->json([
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * List directories in a storage pool's mountpoint.
     */
    public function poolDirectories(string $pool, Request $request): JsonResponse
    {
        $user = $request->user();
        $directories = $this->settingsService->listDirectoriesInPool($pool, $user->username);

        return response()->json([
            'directories' => $directories,
        ]);
    }

    /**
     * List all shares from smb.conf.
     */
    public function shares(): JsonResponse
    {
        $allShares = $this->sambaService->getShares();

        // Filter to show only custom shares and homes
        $shares = array_filter($allShares, function ($share) {
            return $share['type'] === 'custom' || $share['name'] === 'homes';
        });

        // Augment custom shares with filesystem ACL permissions
        $shares = array_map(function ($share) {
            if ($share['type'] === 'custom' && ! empty($share['path'])) {
                $share['user_permissions'] = $this->aclService->getPermissions($share['path']);
            }

            return $share;
        }, $shares);

        // Get the default IP address for network paths
        $ipAddress = $this->networkService->getDefaultIPAddress();

        return response()->json([
            'shares' => array_values($shares),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Create a new share.
     */
    public function createShare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80|regex:/^[a-zA-Z0-9_-]+$/',
            'comment' => 'nullable|string|max:255',
            'path' => 'required|string|max:4096',
            'guest' => 'nullable|in:yes,no,only',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'nullable|in:none,read,readwrite',
        ]);

        $userPermissions = $this->filterUserPermissions($validated['user_permissions'] ?? []);

        try {
            $this->sambaService->createShare($validated['name'], [
                'comment' => $validated['comment'] ?? null,
                'path' => $validated['path'],
                'guest' => $validated['guest'] ?? 'no',
                'user_permissions' => $userPermissions,
            ]);

            $this->aclService->applyPermissions($validated['path'], $userPermissions);
            $this->desktopAccessService->reconcile();

            return response()->json([
                'message' => 'Share created successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update an existing share.
     */
    public function updateShare(Request $request, string $name): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:80|regex:/^[a-zA-Z0-9_-]+$/',
            'comment' => 'nullable|string|max:255',
            'path' => 'nullable|string|max:4096',
            'guest' => 'nullable|in:yes,no,only',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'nullable|in:none,read,readwrite',
        ]);

        // Check if name is being changed to a name that's already used
        $newName = $validated['name'] ?? $name;
        if ($newName !== $name) {
            $existingShare = $this->sambaService->getShare($newName);
            if ($existingShare !== null) {
                return response()->json([
                    'error' => "Share '{$newName}' already exists",
                ], 422);
            }
        }

        $existingShare = $this->sambaService->getShare($name);
        $existingPath = is_string($existingShare['path'] ?? null) ? $existingShare['path'] : null;
        $userPermissions = array_key_exists('user_permissions', $validated)
            ? $this->filterUserPermissions($validated['user_permissions'] ?? [])
            : $this->filterUserPermissions($existingPath ? $this->aclService->getPermissions($existingPath) : []);
        $aliasesToClose = $this->desktopAccessService->aliasesForSambaName($name);

        try {
            $this->sambaService->updateShare($name, [
                'name' => $validated['name'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'path' => $validated['path'] ?? null,
                'guest' => $validated['guest'] ?? null,
                'user_permissions' => $userPermissions,
            ]);

            $sharePath = $validated['path'] ?? $this->sambaService->getShare($newName)['path'] ?? null;
            if ($sharePath && (array_key_exists('user_permissions', $validated) || array_key_exists('path', $validated))) {
                $this->aclService->applyPermissions($sharePath, $userPermissions);
            }

            $this->desktopAccessService->renameFolder($name, $newName, $sharePath);
            $this->desktopAccessService->reconcile($aliasesToClose);

            return response()->json([
                'message' => 'Share updated successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete a share.
     */
    public function deleteShare(string $name): JsonResponse
    {
        try {
            $aliasesToClose = $this->desktopAccessService->aliasesForSambaName($name);
            $this->sambaService->deleteShare($name);
            $this->desktopAccessService->forgetFolder($name);
            $this->desktopAccessService->reconcile($aliasesToClose);

            return response()->json([
                'message' => 'Share deleted successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get available users for share access.
     */
    public function shareUsers(): JsonResponse
    {
        $users = $this->linuxUserService->listUsers();

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * Toggle homes share enabled/disabled.
     */
    public function toggleHomes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        try {
            $aliasesToClose = $this->desktopAccessService->aliasesForSambaName('homes');
            $this->sambaService->setHomesEnabled($validated['enabled']);
            $this->desktopAccessService->reconcile($aliasesToClose);

            return response()->json([
                'message' => 'Homes share '.($validated['enabled'] ? 'enabled' : 'disabled').' successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Filter user_permissions array to only include entries with a non-none level.
     *
     * @param  array<string, string>  $permissions
     * @return array<string, string>
     */
    protected function filterUserPermissions(array $permissions): array
    {
        return array_filter($permissions, function (string $level) {
            return $level !== 'none' && ! empty($level);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
