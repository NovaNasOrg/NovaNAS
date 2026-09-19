<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Linux User Service
 *
 * Provides methods for interacting with Linux system users.
 */
class LinuxUserService
{
    /**
     * System usernames that must never be managed or linked to NovaNAS accounts.
     * The 'novanas' user is the appliance's runtime/system user.
     *
     * @var array<int, string>
     */
    public const SYSTEM_USERNAMES = ['novanas', 'nobody', 'root'];

    /**
     * Get Linux users from the system (UID >= 1000).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function listUsers(): array
    {
        $result = Process::run(['getent', 'passwd']);

        if ($result->failed()) {
            return [];
        }

        $users = [];
        $lines = explode("\n", $result->output());

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = explode(':', $line);

            if (count($parts) >= 3) {
                $username = $parts[0];
                $uid = (int) $parts[2];

                // Only include users with UID >= 1000 (regular users)
                // Exclude NovaNAS system users and other reserved accounts
                if ($uid >= 1000 && ! $this->isReservedUsername($username)) {
                    $users[] = [
                        'value' => $username,
                        'label' => $username.' (UID: '.$uid.')',
                    ];
                }
            }
        }

        return $users;
    }

    /**
     * Check if a username is a reserved NovaNAS system account.
     */
    public function isReservedUsername(string $username): bool
    {
        return in_array($username, self::SYSTEM_USERNAMES, true);
    }

    /**
     * Check if a Linux user exists on the system.
     */
    public function userExists(string $username): bool
    {
        $result = Process::run(['id', $username]);

        return $result->successful();
    }

    /**
     * Get the UID of a Linux user.
     */
    public function getUid(string $username): ?int
    {
        $result = Process::run(['id', '-u', $username]);

        if ($result->failed()) {
            return null;
        }

        return (int) trim($result->output());
    }

    /**
     * Check if a Linux user has a UID below 1000 (system user).
     */
    public function isUidBelow1000(string $username): bool
    {
        $uid = $this->getUid($username);

        if ($uid === null) {
            return false;
        }

        return $uid < 1000;
    }

    /**
     * Check if a username is available (doesn't exist or is a system user).
     *
     * @throws \InvalidArgumentException If the username is a system user
     */
    public function isUsernameAvailable(string $username): bool
    {
        if (! $this->userExists($username)) {
            return true;
        }

        // If user exists, check if it's a system user (UID < 1000)
        if ($this->isUidBelow1000($username)) {
            return false;
        }

        // User exists with UID >= 1000, not available for creation
        return false;
    }

    /**
     * Create a new Linux user.
     *
     * @param  string  $username  The username
     * @param  string  $homeDir  The home directory path
     * @param  string  $password  The initial password
     * @return bool True if successful
     *
     * @throws \InvalidArgumentException If username is a system user
     * @throws \RuntimeException If user creation fails
     */
    public function createUser(string $username, string $homeDir, string $password): bool
    {
        // Check if user already exists
        if ($this->userExists($username)) {
            // Check if it's a system user
            if ($this->isUidBelow1000($username)) {
                throw new \InvalidArgumentException("Cannot create user '{$username}': it is a system user with UID < 1000.");
            }

            // User already exists with valid UID, just return true
            return true;
        }

        // Create user with home directory
        $result = Process::run([
            'sudo',
            'useradd',
            '-m',
            '-d', $homeDir,
            '-s', '/bin/bash',
            $username,
        ]);

        if ($result->failed()) {
            throw new \RuntimeException("Failed to create user '{$username}': ".$result->errorOutput());
        }

        // Set the password
        return $this->updatePassword($username, $password);
    }

    /**
     * Create a locked system identity used only to authenticate a desktop device.
     */
    public function createDesktopDeviceUser(string $username): bool
    {
        if (! preg_match('/^nvd[a-z0-9]{8,17}$/', $username)) {
            throw new \InvalidArgumentException('Invalid desktop device username.');
        }

        if ($this->userExists($username)) {
            return true;
        }

        $result = Process::run([
            'sudo',
            'useradd',
            '--system',
            '--no-create-home',
            '--home-dir', '/nonexistent',
            '--shell', '/usr/sbin/nologin',
            $username,
        ]);

        if ($result->failed()) {
            throw new \RuntimeException("Failed to create desktop device user '{$username}': ".$result->errorOutput());
        }

        return true;
    }

    /**
     * Update a Linux user's password.
     *
     * @param  string  $username  The username
     * @param  string  $password  The new password
     * @return bool True if successful
     *
     * @throws \RuntimeException If password update fails
     */
    public function updatePassword(string $username, string $password): bool
    {
        // Use chpasswd to set the password
        $result = Process::input("{$username}:{$password}")->run([
            'sudo',
            'chpasswd',
        ]);

        if ($result->failed()) {
            throw new \RuntimeException("Failed to update password for user '{$username}': ".$result->errorOutput());
        }

        return true;
    }

    /**
     * Delete a Linux user.
     *
     * @param  string  $username  The username to delete
     * @param  bool  $removeHome  Whether to remove the home directory
     * @return bool True if successful
     *
     * @throws \RuntimeException If user deletion fails
     */
    public function deleteUser(string $username, bool $removeHome = true): bool
    {
        // Check if user exists
        if (! $this->userExists($username)) {
            return true;
        }

        $command = $removeHome
            ? ['sudo', 'userdel', '-r', $username]
            : ['sudo', 'userdel', $username];
        $result = Process::run($command);

        if ($result->failed()) {
            throw new \RuntimeException("Failed to delete user '{$username}': ".$result->errorOutput());
        }

        return true;
    }

    /**
     * Get the home directory of a Linux user.
     */
    public function getHomeDirectory(?string $username = null, bool $allowRoot = false): string
    {
        // Get the username if not specified
        if ($username === null) {
            $username = trim(Process::run('whoami')->output());
        }

        $userResult = Process::run(['getent', 'passwd', $username]);

        $parts = explode(':', $userResult->output());

        if (count($parts) >= 6) {
            return $parts[5];
        } elseif ($allowRoot) {
            return '/root';
        }

        throw new \RuntimeException("Failed to get home directory for user '{$username}'.");
    }
}
