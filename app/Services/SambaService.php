<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Samba Service
 *
 * Provides methods for interacting with Samba (SMB) configuration and password storage.
 */
class SambaService
{
    /**
     * Path to the Samba configuration file.
     */
    protected const SMBCONF_PATH = '/etc/samba/smb.conf';

    /**
     * LinuxUserService instance.
     */
    protected LinuxUserService $linuxUserService;

    /**
     * Create a new Samba service instance.
     */
    public function __construct(LinuxUserService $linuxUserService)
    {
        $this->linuxUserService = $linuxUserService;
    }

    /**
     * Determine if a share is enabled based on its properties.
     * For homes share, it's enabled if the section exists in config.
     */
    protected function isShareEnabled(array $share): bool
    {
        // For homes share, it's enabled if it exists in the config
        if ($share['name'] === 'homes') {
            return true;
        }

        // For custom shares, they're always enabled if they exist
        return $share['type'] === 'custom';
    }

    /**
     * Parse the smb.conf file and return all shares.
     * Homes is enabled if the [homes] section exists in config.
     *
     * @return array<int, array{name: string, type: string, comment: ?string, path: ?string, writable: ?string, guest: ?string, 'valid users': ?string, 'create mask': ?string, 'directory mask': ?string, browseable: ?string, 'read only': ?string, enabled: bool}>
     */
    public function getShares(): array
    {
        if (! file_exists(self::SMBCONF_PATH)) {
            return [];
        }

        $content = file_get_contents(self::SMBCONF_PATH);
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", $content);
        $shares = [];
        $currentShare = null;
        $inGlobal = false;
        $homesInConfig = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === ';' || $trimmed[0] === '#') {
                continue;
            }

            // Check for section headers (share names in brackets)
            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $matches)) {
                // Save previous share if exists
                if ($currentShare !== null) {
                    $shares[] = $currentShare;
                }

                $shareName = $matches[1];
                $inGlobal = ($shareName === 'global');

                // Skip [global] section - it's not a share
                if ($inGlobal) {
                    $currentShare = null;

                    continue;
                }

                $shareType = $this->getShareType($shareName);

                // Track if homes exists in config
                if ($shareName === 'homes') {
                    $homesInConfig = true;
                }

                // Custom and system shares are always enabled if they exist
                // Homes is enabled if it exists in config
                $isEnabled = ($shareType === 'custom' || $shareType === 'system');

                $currentShare = [
                    'name' => $shareName,
                    'type' => $shareType,
                    'comment' => null,
                    'path' => null,
                    'writable' => null,
                    'guest' => null,
                    'valid users' => null,
                    'create mask' => null,
                    'directory mask' => null,
                    'browseable' => null,
                    'read only' => null,
                    'enabled' => $isEnabled,
                ];

                continue;
            }

            // Parse key = value pairs
            if ($currentShare !== null && strpos($trimmed, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $trimmed, 2));

                // Map common variations
                $keyMap = [
                    'comment' => 'comment',
                    'path' => 'path',
                    'writable' => 'writable',
                    'guest ok' => 'guest',
                    'guest' => 'guest',
                    'valid users' => 'valid users',
                    'create mask' => 'create mask',
                    'create mode' => 'create mask',
                    'directory mask' => 'directory mask',
                    'directory mode' => 'directory mask',
                    'browseable' => 'browseable',
                    'read only' => 'read only',
                ];

                $normalizedKey = $keyMap[$key] ?? $key;

                if (array_key_exists($normalizedKey, $currentShare)) {
                    $currentShare[$normalizedKey] = $value;
                }
            }
        }

        // Add last share
        if ($currentShare !== null) {
            $shares[] = $currentShare;
        }

        // If homes doesn't exist in config, add it as disabled
        if (! $homesInConfig) {
            $shares[] = [
                'name' => 'homes',
                'type' => 'homes',
                'comment' => 'Home Directories',
                'path' => null,
                'writable' => 'yes',
                'guest' => null,
                'valid users' => '%S',
                'create mask' => null,
                'directory mask' => null,
                'browseable' => null,
                'read only' => 'yes',
                'enabled' => false,
            ];
        } else {
            // Homes exists in config - it's enabled
            foreach ($shares as &$share) {
                if ($share['name'] === 'homes') {
                    $share['enabled'] = true;
                    break;
                }
            }
        }

        // Sort to put homes first
        usort($shares, function ($a, $b) {
            if ($a['name'] === 'homes') {
                return -1;
            }
            if ($b['name'] === 'homes') {
                return 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $shares;
    }

    /**
     * Determine the type of share.
     */
    protected function getShareType(string $name): string
    {
        if ($name === 'homes') {
            return 'homes';
        }

        if (in_array($name, ['printers', 'print$'], true)) {
            return 'system';
        }

        return 'custom';
    }

    /**
     * Get a specific share by name.
     */
    public function getShare(string $name): ?array
    {
        $shares = $this->getShares();

        foreach ($shares as $share) {
            if ($share['name'] === $name) {
                return $share;
            }
        }

        return null;
    }

    /**
     * Create a new share in smb.conf.
     *
     * @param  array{comment?: string, path: string, guest?: string, user_permissions?: array<string,string>}  $config
     *
     * @throws \RuntimeException
     */
    public function createShare(string $name, array $config): bool
    {
        // Check if share already exists
        if ($this->getShare($name) !== null) {
            throw new \RuntimeException("Share '{$name}' already exists");
        }

        // Validate required fields
        if (empty($config['path'])) {
            throw new \RuntimeException('Path is required for share creation');
        }

        // Read current config
        $content = file_get_contents(self::SMBCONF_PATH);
        if ($content === false) {
            throw new \RuntimeException('Unable to read smb.conf');
        }

        // Build share configuration
        $shareConfig = "\n[{$name}]\n";
        $shareConfig .= $this->buildShareConfig($config);

        // Append to config
        $newContent = $content.$shareConfig;

        // Write to temp file first
        $tempFile = '/tmp/smb.conf.'.uniqid();
        if (file_put_contents($tempFile, $newContent) === false) {
            throw new \RuntimeException('Unable to write temporary config');
        }

        // Validate with testparm
        $result = Process::run(['sudo', 'testparm', '-s', $tempFile]);

        if ($result->failed()) {
            unlink($tempFile);
            throw new \RuntimeException('Invalid smb.conf: '.$result->errorOutput());
        }

        // Move to actual location
        $result = Process::run(['sudo', 'mv', $tempFile, self::SMBCONF_PATH]);

        if ($result->failed()) {
            throw new \RuntimeException('Failed to update smb.conf: '.$result->errorOutput());
        }

        // Restart Samba
        return $this->restartSmb();
    }

    /**
     * Update an existing share.
     *
     * @param  array{comment?: string|null, path?: string|null, guest?: string|null, user_permissions?: array<string,string>|null, name?: string|null}  $config
     *
     * @throws \RuntimeException
     */
    public function updateShare(string $originalName, array $config): bool
    {
        Log::info('[updateShare] Called with originalName: '.$originalName.', config: '.json_encode($config));

        $shares = $this->getShares();
        $shareIndex = null;

        foreach ($shares as $index => $share) {
            if ($share['name'] === $originalName) {
                $shareIndex = $index;
                break;
            }
        }

        if ($shareIndex === null) {
            throw new \RuntimeException("Share '{$originalName}' not found");
        }

        // Cannot edit system shares
        if ($shares[$shareIndex]['type'] === 'system') {
            throw new \RuntimeException('Cannot modify system shares');
        }

        // Check if name is being changed
        $newName = $config['name'] ?? $originalName;
        $isRenaming = $newName !== $originalName;

        // If renaming, check if the new name already exists
        if ($isRenaming) {
            foreach ($shares as $share) {
                if ($share['name'] === $newName) {
                    throw new \RuntimeException("Share '{$newName}' already exists");
                }
            }
        }

        // Merge config - always include all fields from the request
        $currentShare = $shares[$shareIndex];
        Log::info('[updateShare] Current share before merge: '.json_encode($currentShare));

        // Keys provided in the config (even if empty/null) are updated,
        // allowing fields to be explicitly cleared by setting them to an empty string.
        foreach ($config as $key => $value) {
            if ($key !== 'name' && (array_key_exists($key, $currentShare) || $key === 'user_permissions')) {
                // For fields that can be explicitly cleared (like valid users),
                // update when the key was explicitly provided in the config.
                // If value is null or empty, set to empty string to clear the field.
                $currentShare[$key] = ($value === null || $value === '') ? '' : $value;
            }
        }

        Log::info('[updateShare] Current share after merge: '.json_encode($currentShare));

        // Update the shares array with the modified share
        $shares[$shareIndex] = $currentShare;

        // Handle renaming: update the name in the share array
        if ($isRenaming) {
            $currentShare['name'] = $newName;
            // Remove old share and add new one with updated name
            unset($shares[$shareIndex]);
            $shares[] = $currentShare;
            $shares = array_values($shares);
        }

        // Rebuild entire config
        return $this->writeSharesConfig($shares);
    }

    /**
     * Delete a share.
     *
     * @throws \RuntimeException
     */
    public function deleteShare(string $name): bool
    {
        $shares = $this->getShares();
        $shareIndex = null;

        foreach ($shares as $index => $share) {
            if ($share['name'] === $name) {
                $shareIndex = $index;
                break;
            }
        }

        if ($shareIndex === null) {
            throw new \RuntimeException("Share '{$name}' not found");
        }

        // Cannot delete system shares
        if ($shares[$shareIndex]['type'] === 'system') {
            throw new \RuntimeException('Cannot delete system shares');
        }

        // Cannot delete homes share
        if ($shares[$shareIndex]['type'] === 'homes') {
            throw new \RuntimeException('Cannot delete homes share. Use browseable = no to disable.');
        }

        // Remove share
        unset($shares[$shareIndex]);

        return $this->writeSharesConfig(array_values($shares));
    }

    /**
     * Enable or disable the homes share.
     * When enabling: adds the [homes] section
     * When disabling: removes the [homes] section entirely
     */
    public function setHomesEnabled(bool $enabled): bool
    {
        Log::info('[SambaService] setHomesEnabled called with enabled: '.($enabled ? 'true' : 'false'));

        $shares = $this->getShares();
        Log::info('[SambaService] Current shares count: '.count($shares));

        $homesIndex = null;

        foreach ($shares as $index => $share) {
            if ($share['name'] === 'homes') {
                $homesIndex = $index;
                Log::info('[SambaService] Found homes share at index: '.$index);
                break;
            }
        }

        if ($enabled) {
            Log::info('[SambaService] Enabling homes share');
            // Enable: Add [homes] section if it doesn't exist in config
            // Check if homes is in the actual config file, not just in the shares array
            // (getShares() always adds homes with enabled:false when not in config)
            $shares = $this->getShares();
            $homesInConfig = false;

            // Check if homes actually exists in the config file by reading it directly
            $configContent = file_get_contents(self::SMBCONF_PATH);
            if ($configContent !== false && strpos($configContent, '[homes]') !== false) {
                $homesInConfig = true;
            }

            if (! $homesInConfig) {
                // Add homes to the shares array
                $shares[] = [
                    'name' => 'homes',
                    'type' => 'homes',
                    'comment' => 'Home Directories',
                    'enabled' => true,
                ];
                Log::info('[SambaService] Adding homes to shares array and writing config');

                return $this->writeSharesConfig($shares);
            }

            // Homes exists in config but might be disabled in our tracking
            // Update the homes entry and write to ensure it's enabled
            foreach ($shares as &$share) {
                if ($share['name'] === 'homes') {
                    $share['enabled'] = true;
                    Log::info('[SambaService] Updating homes enabled status and writing config');

                    return $this->writeSharesConfig($shares);
                }
            }

            Log::info('[SambaService] Homes already enabled, returning true');

            return true;
        }

        Log::info('[SambaService] Disabling homes share');
        // Disable: Remove [homes] section entirely
        if ($homesIndex !== null) {
            Log::info('[SambaService] Unsetting homes at index: '.$homesIndex);
            unset($shares[$homesIndex]);
            Log::info('[SambaService] Shares count after unset: '.count($shares));
            Log::info('[SambaService] Calling writeSharesConfig');

            return $this->writeSharesConfig(array_values($shares));
        }

        Log::info('[SambaService] Homes not found, nothing to do');

        return true;
    }

    /**
     * Build share configuration string for custom shares.
     *
     * Uses user_permissions to derive valid users, read list, and write list.
     * Always sets writable=yes since filesystem ACLs control actual access.
     *
     * @param  array{comment?: string, path?: string, guest?: string, user_permissions?: array<string,string>, 'create mask'?: string, 'directory mask'?: string}  $config
     */
    protected function buildShareConfig(array $config): string
    {
        $lines = [];

        if (! empty($config['comment'])) {
            $lines[] = "   comment = {$config['comment']}";
        }

        if (! empty($config['path'])) {
            $lines[] = "   path = {$config['path']}";
        }

        // Always writable=yes for custom shares; ACLs control actual access
        $lines[] = '   writable = yes';

        if (! empty($config['guest'])) {
            $lines[] = "   guest = {$config['guest']}";
        }

        // Derive valid users, read list, write list from user_permissions
        $userPermissions = $config['user_permissions'] ?? [];
        $this->appendUserPermissionLines($lines, $userPermissions);

        // Default values (hidden from UI)
        if (empty($config['create mask'])) {
            $lines[] = '   create mask = 0664';
        }

        if (empty($config['directory mask'])) {
            $lines[] = '   directory mask = 0775';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Append valid users / read list / write list lines to config.
     *
     * @param  array<int, string>  $lines
     * @param  array<string, string>  $userPermissions  ['username' => 'read'|'readwrite']
     */
    protected function appendUserPermissionLines(array &$lines, array $userPermissions): void
    {
        $validUsers = [];
        $readList = [];
        $writeList = [];

        foreach ($userPermissions as $username => $level) {
            if ($level === 'none' || empty($level)) {
                continue;
            }

            $validUsers[] = $username;

            if ($level === 'read') {
                $readList[] = $username;
            } elseif ($level === 'readwrite') {
                $writeList[] = $username;
            }
        }

        if (! empty($validUsers)) {
            $lines[] = '   valid users = '.implode(' ', $validUsers);
        }

        if (! empty($readList)) {
            $lines[] = '   read list = '.implode(' ', $readList);
        }

        if (! empty($writeList)) {
            $lines[] = '   write list = '.implode(' ', $writeList);
        }
    }

    /**
     * Write all shares to smb.conf.
     * Preserves the entire [global] section and all other sections.
     *
     * @param  array<int, array{name: string, type: string, comment?: ?string, path?: ?string, guest?: ?string, user_permissions?: array<string,string>, 'valid users'?: ?string, 'create mask'?: ?string, 'directory mask'?: ?string, browseable?: ?string, enabled?: bool}>  $shares
     *
     * @throws \RuntimeException
     */
    protected function writeSharesConfig(array $shares): bool
    {
        Log::info('[writeSharesConfig] Called with '.count($shares).' shares');

        // DEBUG: Log the actual share data being passed
        foreach ($shares as $s) {
            if ($s['name'] === 'share') {
                Log::info('[writeSharesConfig] DEBUG share data: '.json_encode($s));
            }
        }

        // Read existing config
        $existingContent = file_get_contents(self::SMBCONF_PATH);
        if ($existingContent === false) {
            throw new \RuntimeException('Unable to read smb.conf');
        }

        Log::info('[writeSharesConfig] Existing config size: '.strlen($existingContent));

        // Parse the existing config into sections
        $sections = [];
        $currentSection = null;
        $lines = preg_split('/\r?\n/', $existingContent);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $matches)) {
                $currentSection = $matches[1];
                $sections[$currentSection] = [];

                continue;
            }

            if ($currentSection !== null) {
                $sections[$currentSection][] = $line;
            }
        }

        Log::info('[writeSharesConfig] Parsed sections: '.implode(', ', array_keys($sections)));

        // Update or add share sections
        foreach ($shares as $share) {
            $shareName = $share['name'];
            Log::info('[writeSharesConfig] Processing share: '.$shareName.' (type: '.$share['type'].')');
            $shareConfig = [];

            // Add configured options
            if (! empty($share['comment'])) {
                $shareConfig[] = "   comment = {$share['comment']}";
            }

            if (! empty($share['path']) && $share['type'] !== 'homes') {
                $shareConfig[] = "   path = {$share['path']}";
            }

            if (! empty($share['guest']) && $share['type'] !== 'homes') {
                $shareConfig[] = "   guest = {$share['guest']}";
            }

            // Custom shares: derive valid users / read list / write list from user_permissions
            if ($share['type'] === 'custom') {
                $shareConfig[] = '   writable = yes';
                $userPermissions = $share['user_permissions'] ?? [];
                $this->appendUserPermissionLines($shareConfig, $userPermissions);
            }

            // Non-custom, non-homes shares (system): preserve valid users if present
            if ($share['type'] !== 'homes' && $share['type'] !== 'custom' && ! empty($share['valid users'])) {
                $shareConfig[] = "   valid users = {$share['valid users']}";
            }

            // Create/directory masks for custom shares only
            if ($share['type'] === 'custom') {
                $shareConfig[] = '   create mask = 0664';
                $shareConfig[] = '   directory mask = 0775';
            }

            // Homes share options - add valid users and browseable always, add default comment only if not provided
            if ($share['type'] === 'homes') {
                if (empty($share['comment'])) {
                    $shareConfig[] = '   comment = Home Directories';
                }
                if (empty($share['valid users'])) {
                    $shareConfig[] = '   valid users = %S';
                }
                // Homes should not be browsable by default (security)
                if (empty($share['browseable'])) {
                    $shareConfig[] = '   browseable = no';
                }
                // Homes always writable
                $shareConfig[] = '   writable = yes';
                // Strict masks for home directories - only owner can access
                $shareConfig[] = '   create mask = 0700';
                $shareConfig[] = '   directory mask = 0700';
            }

            $sections[$shareName] = $shareConfig;
        }

        // Remove sections that are not in the shares array (they were removed or disabled)
        // This ensures that when a share is removed from $shares, it's also removed from config
        $shareNames = array_column($shares, 'name');
        foreach (array_keys($sections) as $sectionName) {
            // Skip global - it's handled separately
            if ($sectionName === 'global') {
                continue;
            }
            // Remove sections not in the shares array
            if (! in_array($sectionName, $shareNames, true)) {
                unset($sections[$sectionName]);
            }
        }

        Log::info('[writeSharesConfig] Sections after cleanup: '.implode(', ', array_keys($sections)));

        // Reconstruct the config file
        $output = '';

        // First output the global section if it exists (preserve original)
        if (isset($sections['global'])) {
            $output .= "[global]\n".implode("\n", $sections['global'])."\n\n";
            unset($sections['global']);
        }

        // Then output all other sections (including shares)
        foreach ($sections as $sectionName => $sectionLines) {
            $output .= "[{$sectionName}]\n";
            if (! empty($sectionLines)) {
                $output .= implode("\n", $sectionLines)."\n";
            }
            $output .= "\n";
        }

        Log::info('[writeSharesConfig] Output size: '.strlen($output));

        // Write to temp file
        $tempFile = '/tmp/smb.conf.'.uniqid();
        if (file_put_contents($tempFile, $output) === false) {
            throw new \RuntimeException('Unable to write temporary config');
        }

        Log::info('[writeSharesConfig] Written to temp file: '.$tempFile);

        // DEBUG: Log temp file content
        $tempContent = file_get_contents($tempFile);
        Log::info('[writeSharesConfig] Temp file [share] section: '.(preg_match('/\[share\](.*?)(?=\[|$)/s', $tempContent, $m) ? $m[0] : 'NOT FOUND'));

        // Validate
        $result = Process::run(['sudo', 'testparm', '-s', $tempFile]);

        if ($result->failed()) {
            unlink($tempFile);
            Log::error('[writeSharesConfig] testparm failed: '.$result->errorOutput());
            throw new \RuntimeException('Invalid smb.conf: '.$result->errorOutput());
        }

        Log::info('[writeSharesConfig] testparm passed');

        // Move to actual location
        $result = Process::run(['sudo', 'mv', $tempFile, self::SMBCONF_PATH]);

        // DEBUG: Log the actual output
        $stdout = $result->output();
        $stderr = $result->errorOutput();
        $exitCode = $result->exitCode();

        Log::info('[writeSharesConfig] mv stdout: "'.$stdout.'"');
        Log::info('[writeSharesConfig] mv stderr: "'.$stderr.'"');
        Log::info('[writeSharesConfig] mv exitCode: '.$exitCode);

        if ($result->failed()) {
            Log::error('[writeSharesConfig] mv failed: '.$result->errorOutput());
            throw new \RuntimeException('Failed to update smb.conf: '.$result->errorOutput());
        }

        Log::info('[writeSharesConfig] File moved successfully');

        // DEBUG: Verify the file was actually written
        $verifyContent = file_get_contents(self::SMBCONF_PATH);
        Log::info('[writeSharesConfig] Verified file content (first 200 chars): '.substr($verifyContent, 0, 200));

        return $this->restartSmb();
    }

    /**
     * Restart Samba services.
     */
    public function restartSmb(): bool
    {
        Log::info('[restartSmb] Starting restart...');

        // Try to restart smbd first, then nmbd
        $result = Process::run(['sudo', 'systemctl', 'restart', 'smbd']);

        Log::info('[restartSmb] smbd restart stdout: '.$result->output());
        Log::info('[restartSmb] smbd restart stderr: '.$result->errorOutput());
        Log::info('[restartSmb] smbd restart exitCode: '.$result->exitCode());

        if ($result->failed()) {
            // Try alternative service names
            $result = Process::run(['sudo', 'service', 'smbd', 'restart']);

            if ($result->failed()) {
                // Try smbd directly
                $result = Process::run(['sudo', 'smbd', '--reload-config']);
            }
        }

        // Also restart nmbd if it exists
        $result = Process::run(['sudo', 'systemctl', 'restart', 'nmbd']);

        Log::info('[restartSmb] nmbd restart exitCode: '.$result->exitCode());

        return true;
    }

    /**
     * Check if a Samba user exists in the system.
     */
    public function userExists(string $username): bool
    {
        $result = Process::run(['sudo', 'pdbedit', '-L']);

        if ($result->failed()) {
            return false;
        }

        $lines = explode("\n", $result->output());

        // pdbedit -L output format is: username:uid:gecos
        // We need to extract just the username (part before first colon)
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }
            $parts = explode(':', $trimmed);
            if ($parts[0] === $username) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the UID of a Linux user (required for Samba passdb format).
     */
    public function getUid(string $username): ?int
    {
        return $this->linuxUserService->getUid($username);
    }

    /**
     * Generate Samba NT (MD4) password hash from the user's password.
     */
    public function generateNtHash(string $password): string
    {
        if (empty($password)) {
            return '';
        }

        // Convert UTF-8 to UTF-16LE
        $unicode = iconv('UTF-8', 'UTF-16LE', $password);

        // Compute MD4 hash, uppercase hex
        return strtoupper(bin2hex(hash('md4', $unicode, true)));
    }

    /**
     * Generate Samba LM (LAN Manager) hash.
     *
     * Note: LM hash is deprecated and we use a placeholder for NT-only auth.
     */
    public function generateLmHash(): string
    {
        // LM hash is disabled for modern authentication
        // Using placeholder for NT-only authentication
        return 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
    }

    /**
     * Create a Samba passdb line format.
     *
     * Format: username:uid:lm_hash:nt_hash:[U          ]:LCT-timestamp
     */
    public function createPassdbLine(string $username, int $uid, string $ntHash): string
    {
        $lmHash = $this->generateLmHash();
        $timestamp = time();

        return sprintf(
            '%s:%d:%s:%s:[U          ]:LCT-%d',
            $username,
            $uid,
            $lmHash,
            $ntHash,
            $timestamp
        );
    }

    /**
     * Add or update a Samba user password.
     *
     * This method uses smbpasswd command to update the Samba password.
     * The -a flag adds or updates the user, -s reads from stdin.
     *
     * @param  string  $username  The username
     * @param  string  $password  The plain text password
     * @return bool True if successful
     *
     * @throws \RuntimeException If the operation fails
     */
    public function updatePassword(string $username, string $password): bool
    {
        // Verify the user exists on the system
        if (! $this->linuxUserService->userExists($username)) {
            throw new \RuntimeException("Cannot update Samba password: user '{$username}' not found on system.");
        }

        $passwordWithConfirmation = $password."\n".$password."\n";

        $result = Process::input($passwordWithConfirmation)->run([
            'sudo',
            'smbpasswd',
            '-a',
            '-s',
            $username,
        ]);

        if ($result->failed()) {
            throw new \RuntimeException('Failed to update Samba password: '.$result->errorOutput());
        }

        return true;
    }

    /**
     * Delete a Samba user.
     *
     * @param  string  $username  The username to delete
     * @return bool True if successful
     *
     * @throws \RuntimeException If deletion fails
     */
    public function deleteUser(string $username): bool
    {
        if (! $this->userExists($username)) {
            return true;
        }

        $result = Process::run(['sudo', 'pdbedit', '-x', '-u', $username]);

        if ($result->failed()) {
            throw new \RuntimeException("Failed to delete Samba user '{$username}': ".$result->errorOutput());
        }

        return true;
    }

    /**
     * Clean up temporary file.
     */
    protected function cleanupTempFile(string $tempFile): void
    {
        Process::run(['sudo', 'rm', '-f', $tempFile]);
    }
}
