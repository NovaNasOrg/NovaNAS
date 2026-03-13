<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\LinuxUserService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(
        public LinuxUserService $linuxUserService,
        public SettingsService $settingsService
    ) {
    }

    /**
     * List all active users.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->get('search', '');
        $sortBy = $request->get('sortBy', 'created_at');
        $sortDir = $request->get('sortDir', 'desc');

        $users = User::active()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortDir)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_admin' => $user->is_admin,
                    'created_at' => $user->created_at->toIso8601String(),
                ];
            });

        return response()->json(['users' => $users]);
    }

    /**
     * List pending (invited) users.
     */
    public function pending(): JsonResponse
    {
        $invitations = User::pending()
            ->whereNotNull('invitation_expires_at')
            ->where('invitation_expires_at', '>', now())
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_admin' => $user->is_admin,
                    'expires_at' => $user->invitation_expires_at->toIso8601String(),
                    'invitation_token' => $user->invitation_token,
                    'created_at' => $user->created_at->toIso8601String(),
                ];
            });

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * Create a new user directly (admin creates user with password).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check if username is a system user
        if ($this->linuxUserService->userExists($validated['username'])) {
            if ($this->linuxUserService->isUidBelow1000($validated['username'])) {
                return response()->json([
                    'error' => 'Cannot create user. The username is a system user with UID < 1000.',
                ], 422);
            }
            // User exists but is not a system user - we'll use the existing Linux user
        }

        // Get the home directory base path
        $homeBase = $this->settingsService->get('storage.user_files_home', '/home');
        $homeDir = $homeBase . '/' . $validated['username'];

        // Generate a random temporary password
        $tempPassword = Str::random(16);

        // Create the Linux user (or link to existing)
        try {
            $this->linuxUserService->createUser(
                $validated['username'],
                $homeDir,
                $tempPassword
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Failed to create Linux user: ' . $e->getMessage(),
            ], 500);
        }

        // Create the user in the database
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
            'status' => 'active',
            'password_set_at' => now(),
        ]);

        // Update the Linux user password with the actual password
        try {
            $this->linuxUserService->updatePassword(
                $validated['username'],
                $validated['password']
            );
        } catch (\RuntimeException $e) {
            // Log warning but don't fail the request
            report($e);
        }

        return response()->json([
            'message' => 'User created successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'is_admin' => $user->is_admin,
            ],
        ], 201);
    }

    /**
     * Invite a new user via email or invitation link.
     */
    public function invite(InviteUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $username = $validated['username'];

        // Check if username is a system user
        if ($this->linuxUserService->userExists($username)) {
            if ($this->linuxUserService->isUidBelow1000($username)) {
                return response()->json([
                    'error' => 'Cannot use this username. It is a system user with UID < 1000.',
                ], 422);
            }
            // User exists but is not a system user - we'll use the existing Linux user
        }

        // Generate invitation token
        $token = Str::uuid()->toString();
        $expiresInHours = (int) $this->settingsService->get('users.invitation_lifetime_hours', 48);
        $expiresAt = now()->addHours($expiresInHours);

        // Get the home directory base path
        $homeBase = $this->settingsService->get('storage.user_files_home', '/home');
        $homeDir = $homeBase . '/' . $username;

        // Create the Linux user (or link to existing)
        $tempPassword = Str::random(16);
        try {
            $this->linuxUserService->createUser($username, $homeDir, $tempPassword);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Failed to create Linux user: ' . $e->getMessage(),
            ], 500);
        }

        // Create the user as pending
        $user = User::create([
            'name' => $validated['email'], // Use email as temporary name
            'email' => $validated['email'],
            'username' => $username,
            'password' => null, // No password yet
            'is_admin' => $validated['is_admin'] ?? false,
            'status' => 'pending',
            'invitation_token' => $token,
            'invitation_expires_at' => $expiresAt,
        ]);

        // Generate the invitation URL
        $appUrl = config('app.url', 'http://localhost');
        $invitationUrl = "{$appUrl}/set-password?token={$token}";

        return response()->json([
            'message' => 'Invitation created successfully',
            'invitation' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'is_admin' => $user->is_admin,
                'expires_at' => $user->invitation_expires_at->toIso8601String(),
                'invitation_token' => $token,
                'invitation_url' => $invitationUrl,
            ],
        ], 201);
    }

    /**
     * Update an existing user.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        // Username cannot be changed after creation
        if (isset($validated['username'])) {
            unset($validated['username']);
        }

        // Update user (only allow name, email, is_admin)
        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent deleting yourself
        $currentUser = auth()->user();
        if ($currentUser && $user->id === $currentUser->id) {
            return response()->json([
                'error' => 'You cannot delete your own account.',
            ], 422);
        }

        // Prevent deleting the default admin user (first user created)
        $firstUserId = User::orderBy('id', 'asc')->value('id');
        if ($user->id === $firstUserId) {
            return response()->json([
                'error' => 'Cannot delete the default admin user.',
            ], 422);
        }

        $username = $user->username;

        // Delete the user from database
        $user->delete();

        // Try to delete the Linux user (but don't fail if it doesn't work)
        if ($username) {
            try {
                $this->linuxUserService->deleteUser($username, true);
            } catch (\RuntimeException $e) {
                // Log warning but don't fail
                report($e);
            }
        }

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Revoke an invitation.
     */
    public function revokeInvitation(User $user): JsonResponse
    {
        if (!$user->isPending()) {
            return response()->json([
                'error' => 'This user is not a pending invitation.',
            ], 422);
        }

        $username = $user->username;

        // Delete the user from database
        $user->delete();

        // Try to delete the Linux user if one was created
        if ($username) {
            try {
                $this->linuxUserService->deleteUser($username, true);
            } catch (\RuntimeException $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Invitation revoked successfully',
        ]);
    }

    /**
     * Show the set password page for invited users.
     */
    public function showSetPassword(string $token): \Inertia\Response
    {
        $user = User::where('invitation_token', $token)->first();

        if (!$user) {
            abort(404, 'Invalid invitation token.');
        }

        if (!$user->canSetPassword()) {
            abort(400, 'This invitation has expired or is invalid.');
        }

        return Inertia::render('SetPassword', [
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    /**
     * Set password for invited user.
     */
    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('invitation_token', $validated['token'])->first();

        if (!$user) {
            return response()->json([
                'error' => 'Invalid invitation token.',
            ], 422);
        }

        if (!$user->canSetPassword()) {
            return response()->json([
                'error' => 'This invitation has expired or is invalid.',
            ], 422);
        }

        // Update the password
        $user->update([
            'password' => Hash::make($validated['password']),
            'status' => 'active',
            'password_set_at' => now(),
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'name' => $user->username ?? $user->email, // Use username as name if available
        ]);

        // Update the Linux user password
        if ($user->username) {
            try {
                $this->linuxUserService->updatePassword(
                    $user->username,
                    $validated['password']
                );
            } catch (\RuntimeException $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Password set successfully. You can now log in.',
        ]);
    }

    /**
     * Get available Linux users (for linking).
     */
    public function availableLinuxUsers(): JsonResponse
    {
        $users = $this->linuxUserService->listUsers();

        // Filter out users that are already linked
        $linkedUsernames = User::whereNotNull('username')
            ->pluck('username')
            ->toArray();

        $availableUsers = array_filter($users, function ($user) use ($linkedUsernames) {
            return !in_array($user['value'], $linkedUsernames);
        });

        return response()->json(['users' => array_values($availableUsers)]);
    }
}
