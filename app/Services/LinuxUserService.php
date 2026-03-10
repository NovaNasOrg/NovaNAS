<?php

namespace App\Services;

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
}
