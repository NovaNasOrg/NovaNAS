<?php

namespace App\Services;

use App\Models\DesktopDevice;
use App\Models\DesktopSharedFolder;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DesktopAccessService
{
    private const DESKTOP_CONFIG_PATH = '/etc/samba/novanas-desktop.conf';

    private const MAIN_CONFIG_PATH = '/etc/samba/smb.conf';

    public function __construct(
        private SambaService $sambaService,
        private AclService $aclService,
        private LinuxUserService $linuxUserService,
    ) {}

    /**
     * @return array{device: DesktopDevice, password: string}
     */
    public function provisionDevice(User $user, string $uuid, string $name, string $platform): array
    {
        if (! $user->username) {
            throw new \RuntimeException('This NovaNAS account is not linked to a system user.');
        }

        $device = DesktopDevice::query()
            ->where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (! $device) {
            $device = new DesktopDevice([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'samba_username' => $this->newDeviceUsername($user),
            ]);
        }

        $password = Str::random(48);

        $this->linuxUserService->createDesktopDeviceUser($device->samba_username);
        $this->sambaService->updatePassword($device->samba_username, $password);

        $device->fill([
            'name' => $name,
            'platform' => $platform,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ])->save();

        $this->reconcile();

        return ['device' => $device, 'password' => $password];
    }

    public function revokeDevice(User $user, DesktopDevice $device): void
    {
        if ($device->user_id !== $user->id) {
            abort(404);
        }

        $aliases = $this->aliasesForUser($user);
        $device->update(['revoked_at' => now()]);
        $this->sambaService->deleteUser($device->samba_username);
        $this->reconcile($aliases);
        $this->linuxUserService->deleteUser($device->samba_username, false);
    }

    public function revokeAllForUser(User $user): void
    {
        $aliases = $this->aliasesForUser($user);
        $devices = $user->desktopDevices()->whereNull('revoked_at')->get();

        foreach ($devices as $device) {
            $this->sambaService->deleteUser($device->samba_username);
            $device->update(['revoked_at' => now()]);
        }

        $this->reconcile($aliases);

        foreach ($devices as $device) {
            $this->linuxUserService->deleteUser($device->samba_username, false);
        }
    }

    /**
     * @return array<int, array{id: string, name: string, comment: ?string, permission: string, connection_name: string}>
     */
    public function foldersForUser(User $user): array
    {
        if (! $user->username) {
            return [];
        }

        $shares = $this->syncFolderCatalog();
        $folders = [];

        foreach ($shares as $share) {
            $folder = DesktopSharedFolder::query()->where('samba_name', $share['name'])->first();

            if (! $folder) {
                continue;
            }

            $permission = $this->permissionFor($user, $share);

            if ($permission === null) {
                continue;
            }

            $folders[] = [
                'id' => $folder->uuid,
                'name' => $share['name'] === 'homes' ? $user->username : $share['name'],
                'comment' => $share['name'] === 'homes' ? 'Personal folder' : ($share['comment'] ?? null),
                'permission' => $permission,
                'connection_name' => $this->aliasFor($user, $folder),
            ];
        }

        return $folders;
    }

    /**
     * Rebuild every generated endpoint from the current NovaNAS permissions.
     *
     * @param  array<int, string>  $aliasesToClose
     */
    public function reconcile(array $aliasesToClose = []): void
    {
        $shares = $this->syncFolderCatalog();
        $entries = [];
        $activeDevices = DesktopDevice::query()
            ->active()
            ->with('user')
            ->get();
        $devicesByUser = $activeDevices
            ->filter(fn (DesktopDevice $device) => $device->user->isActive() && $device->user->username)
            ->groupBy('user_id');
        $blockedHomeUsers = collect($shares)->contains(
            fn (array $share) => $share['name'] === 'homes' && $share['enabled'],
        )
            ? $activeDevices->pluck('samba_username')->unique()->values()->all()
            : [];

        foreach ($devicesByUser as $devices) {
            $user = $devices->first()->user;

            foreach ($shares as $share) {
                $folder = DesktopSharedFolder::query()->where('samba_name', $share['name'])->first();
                $permission = $this->permissionFor($user, $share);

                if (! $folder || $permission === null) {
                    continue;
                }

                $path = $share['name'] === 'homes'
                    ? $this->linuxUserService->getHomeDirectory($user->username)
                    : $share['path'];

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $entries[] = [
                    'alias' => $this->aliasFor($user, $folder),
                    'path' => $path,
                    'username' => $user->username,
                    'device_usernames' => $share['name'] === 'homes'
                        ? $devices->pluck('samba_username')->push($user->username)->unique()->values()->all()
                        : $devices->pluck('samba_username')->all(),
                    'read_only' => $permission === 'read',
                    'browseable' => $share['name'] === 'homes',
                ];

                if ($share['name'] === 'homes') {
                    $aliasesToClose[] = $this->legacyAliasFor($user, $folder);
                }
            }
        }

        $this->writeConfiguration($this->renderConfiguration($entries, $blockedHomeUsers));

        $reload = Process::run(['sudo', 'smbcontrol', 'smbd', 'reload-config']);
        if ($reload->failed()) {
            throw new \RuntimeException('Failed to reload shared-folder configuration: '.$reload->errorOutput());
        }

        foreach (array_unique($aliasesToClose) as $alias) {
            Process::run(['sudo', 'smbcontrol', 'smbd', 'close-share', $alias]);
        }

        // The standard personal-folder section derives a folder name from the
        // login identity. Device identities must never get one of those folders.
        foreach ($blockedHomeUsers as $deviceUsername) {
            Process::run(['sudo', 'smbcontrol', 'smbd', 'close-share', $deviceUsername]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function aliasesForSambaName(string $sambaName): array
    {
        $folder = DesktopSharedFolder::query()->where('samba_name', $sambaName)->first();

        if (! $folder) {
            return [];
        }

        return User::active()
            ->whereNotNull('username')
            ->get()
            ->map(fn (User $user) => $this->aliasFor($user, $folder))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function aliasesForUser(User $user): array
    {
        return DesktopSharedFolder::query()
            ->get()
            ->map(fn (DesktopSharedFolder $folder) => $this->aliasFor($user, $folder))
            ->all();
    }

    public function renameFolder(string $oldName, string $newName, ?string $path): void
    {
        DesktopSharedFolder::query()->where('samba_name', $oldName)->update([
            'samba_name' => $newName,
            'path' => $path,
        ]);
    }

    public function forgetFolder(string $sambaName): void
    {
        DesktopSharedFolder::query()->where('samba_name', $sambaName)->delete();
    }

    /**
     * @param  array<int, array{alias: string, path: string, username: string, device_usernames: array<int, string>, read_only: bool, browseable?: bool}>  $entries
     * @param  array<int, string>  $blockedHomeUsers
     */
    public function renderConfiguration(array $entries, array $blockedHomeUsers = []): string
    {
        $output = "# Managed by NovaNAS. Manual changes will be overwritten.\n\n";

        if ($blockedHomeUsers !== []) {
            foreach ($blockedHomeUsers as $username) {
                if (str_contains($username, "\n") || str_contains($username, "\r")) {
                    throw new \RuntimeException('Invalid newline in generated shared-folder configuration.');
                }
            }

            $output .= "[homes]\n";
            $output .= '   invalid users = '.implode(' ', $blockedHomeUsers)."\n";
            $output .= "   access based share enum = yes\n\n";
        }

        foreach ($entries as $entry) {
            foreach ([$entry['alias'], $entry['path'], $entry['username'], ...$entry['device_usernames']] as $value) {
                if (str_contains($value, "\n") || str_contains($value, "\r")) {
                    throw new \RuntimeException('Invalid newline in generated shared-folder configuration.');
                }
            }

            $output .= "[{$entry['alias']}]\n";
            $output .= "   path = {$entry['path']}\n";
            $output .= '   browseable = '.(($entry['browseable'] ?? false) ? 'yes' : 'no')."\n";
            $output .= "   guest ok = no\n";
            $output .= '   valid users = '.implode(' ', $entry['device_usernames'])."\n";
            $output .= "   force user = {$entry['username']}\n";
            $output .= '   read only = '.($entry['read_only'] ? 'yes' : 'no')."\n";
            $output .= "   create mask = 0664\n";
            $output .= "   directory mask = 0775\n\n";
        }

        return $output;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncFolderCatalog(): array
    {
        $shares = array_values(array_filter(
            $this->sambaService->getShares(),
            fn (array $share) => $share['type'] === 'custom'
                || ($share['name'] === 'homes' && $share['enabled']),
        ));

        $activeNames = [];

        foreach ($shares as $share) {
            $activeNames[] = $share['name'];
            DesktopSharedFolder::query()->firstOrCreate(
                ['samba_name' => $share['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'path' => $share['path'] ?? null,
                    'type' => $share['type'],
                ],
            );

            DesktopSharedFolder::query()->where('samba_name', $share['name'])->update([
                'path' => $share['path'] ?? null,
                'type' => $share['type'],
            ]);
        }

        DesktopSharedFolder::query()->whereNotIn('samba_name', $activeNames)->delete();

        return $shares;
    }

    /**
     * @param  array<string, mixed>  $share
     */
    private function permissionFor(User $user, array $share): ?string
    {
        if ($share['name'] === 'homes') {
            return 'readwrite';
        }

        if (! is_string($share['path'] ?? null) || ! $user->username) {
            return null;
        }

        $permission = $this->aclService->getPermissions($share['path'])[$user->username] ?? null;

        if (in_array($permission, ['read', 'readwrite'], true)) {
            return $permission;
        }

        if (in_array($share['guest'] ?? null, ['yes', 'only'], true)) {
            return ($share['read only'] ?? null) === 'yes' ? 'read' : 'readwrite';
        }

        return null;
    }

    private function aliasFor(User $user, DesktopSharedFolder $folder): string
    {
        if ($folder->type === 'homes' && $user->username) {
            $hasCollision = DesktopSharedFolder::query()
                ->where('type', 'custom')
                ->where('samba_name', $user->username)
                ->exists();

            return $hasCollision ? $user->username.'-home' : $user->username;
        }

        return $this->legacyAliasFor($user, $folder);
    }

    private function legacyAliasFor(User $user, DesktopSharedFolder $folder): string
    {
        return 'nvd-u'.$user->id.'-'.Str::lower(Str::substr(str_replace('-', '', $folder->uuid), 0, 12));
    }

    private function newDeviceUsername(User $user): string
    {
        do {
            $username = 'nvd'.base_convert((string) $user->id, 10, 36).Str::lower(Str::random(12));
            $username = Str::substr($username, 0, 20);
        } while (DesktopDevice::query()->where('samba_username', $username)->exists());

        return $username;
    }

    private function writeConfiguration(string $configuration): void
    {
        if (! file_exists(self::MAIN_CONFIG_PATH)) {
            throw new \RuntimeException('The shared-folder service is not configured on this system.');
        }

        $desktopTemp = tempnam(sys_get_temp_dir(), 'novanas-desktop-');
        $mainTemp = tempnam(sys_get_temp_dir(), 'novanas-main-');
        $validationTemp = tempnam(sys_get_temp_dir(), 'novanas-validate-');

        if (! $desktopTemp || ! $mainTemp || ! $validationTemp) {
            throw new \RuntimeException('Unable to create temporary configuration files.');
        }

        try {
            file_put_contents($desktopTemp, $configuration);
            file_put_contents($validationTemp, "[global]\ninclude = {$desktopTemp}\n");

            $validation = Process::run(['sudo', 'testparm', '-s', $validationTemp]);
            if ($validation->failed()) {
                throw new \RuntimeException('Invalid generated shared-folder configuration: '.$validation->errorOutput());
            }

            $installDesktop = Process::run(['sudo', 'install', '-m', '0644', $desktopTemp, self::DESKTOP_CONFIG_PATH]);
            if ($installDesktop->failed()) {
                throw new \RuntimeException('Failed to install generated shared-folder configuration: '.$installDesktop->errorOutput());
            }

            $mainConfiguration = file_get_contents(self::MAIN_CONFIG_PATH);
            if ($mainConfiguration === false) {
                throw new \RuntimeException('Unable to read the shared-folder configuration.');
            }

            $includeLine = '   include = '.self::DESKTOP_CONFIG_PATH;
            $hasActiveInclude = preg_match(
                '/^\s*include\s*=\s*'.preg_quote(self::DESKTOP_CONFIG_PATH, '/').'\s*$/mi',
                $mainConfiguration,
            ) === 1;
            if (! $hasActiveInclude) {
                $mainConfiguration = preg_replace('/\[global\]\s*/', "[global]\n{$includeLine}\n", $mainConfiguration, 1, $count);
                if ($count !== 1 || $mainConfiguration === null) {
                    throw new \RuntimeException('Unable to add the desktop include to the shared-folder configuration.');
                }

                file_put_contents($mainTemp, $mainConfiguration);
                $validation = Process::run(['sudo', 'testparm', '-s', $mainTemp]);
                if ($validation->failed()) {
                    throw new \RuntimeException('Invalid shared-folder configuration: '.$validation->errorOutput());
                }

                $installMain = Process::run(['sudo', 'install', '-m', '0644', $mainTemp, self::MAIN_CONFIG_PATH]);
                if ($installMain->failed()) {
                    throw new \RuntimeException('Failed to update the shared-folder configuration: '.$installMain->errorOutput());
                }
            }
        } finally {
            @unlink($desktopTemp);
            @unlink($mainTemp);
            @unlink($validationTemp);
        }
    }
}
