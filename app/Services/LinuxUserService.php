<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Linux User Service
 *
 * Provides methods for interacting with Linux system users.
 */
class LinuxUserService
{
    /**
     * Get Linux users from the system (UID >= 1000).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function listUsers(): array
    {
        $process = new Process(['getent', 'passwd']);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $users = [];
        $lines = explode("\n", $process->getOutput());

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = explode(':', $line);

            if (count($parts) >= 3) {
                $username = $parts[0];
                $uid = (int) $parts[2];

                // Only include users with UID >= 1000 (regular users)
                // Exclude system accounts like 'nobody'
                if ($uid >= 1000 && $username !== 'nobody') {
                    $users[] = [
                        'value' => $username,
                        'label' => $username . ' (UID: ' . $uid . ')',
                    ];
                }
            }
        }

        return $users;
    }

    /**
     * Check if a Linux user exists on the system.
     */
    public function userExists(string $username): bool
    {
        $process = new Process(['id', $username]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get the UID of a Linux user.
     */
    public function getUid(string $username): ?int
    {
        $process = new Process(['id', '-u', $username]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return (int) trim($process->getOutput());
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
        if (!$this->userExists($username)) {
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
     * @param string $username The username
     * @param string $homeDir The home directory path
     * @param string $password The initial password
     * @return bool True if successful
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
        $process = new Process([
            'useradd',
            '-m',
            '-d', $homeDir,
            '-s', '/bin/bash',
            $username,
        ]);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException("Failed to create user '{$username}': " . $process->getErrorOutput());
        }

        // Set the password
        return $this->updatePassword($username, $password);
    }

    /**
     * Update a Linux user's password.
     *
     * @param string $username The username
     * @param string $password The new password
     * @return bool True if successful
     * @throws \RuntimeException If password update fails
     */
    public function updatePassword(string $username, string $password): bool
    {
        // Use chpasswd to set the password
        $process = new Process([
            'chpasswd',
        ]);

        $process->setInput("{$username}:{$password}");

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException("Failed to update password for user '{$username}': " . $process->getErrorOutput());
        }

        return true;
    }

    /**
     * Delete a Linux user.
     *
     * @param string $username The username to delete
     * @param bool $removeHome Whether to remove the home directory
     * @return bool True if successful
     * @throws \RuntimeException If user deletion fails
     */
    public function deleteUser(string $username, bool $removeHome = true): bool
    {
        // Check if user exists
        if (!$this->userExists($username)) {
            return true;
        }

        $command = $removeHome ? ['userdel', '-r', $username] : ['userdel', $username];
        $process = new Process($command);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException("Failed to delete user '{$username}': " . $process->getErrorOutput());
        }

        return true;
    }

    /**
     * Get the home directory of a Linux user.
     */
    public function getHomeDirectory(string $username): ?string
    {
        $process = new Process(['getent', 'passwd', $username]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $parts = explode(':', $process->getOutput());

        if (count($parts) >= 6) {
            return $parts[5];
        }

        return null;
    }
}
