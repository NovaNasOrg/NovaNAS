<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LinuxUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WizardController extends Controller
{
    public function __construct(public LinuxUserService $linuxUserService)
    {
    }

    /**
     * Check if the wizard should run (no users exist).
     */
    public function shouldRun(): bool
    {
        return User::query()->doesntExist();
    }

    /**
     * Show the wizard index (welcome page).
     */
    public function index()
    {
        if (!$this->shouldRun()) {
            return redirect('/');
        }

        return inertia('Wizard/Welcome');
    }

    /**
     * Show the account creation step.
     */
    public function account()
    {
        if (!$this->shouldRun()) {
            return redirect('/');
        }

        return inertia('Wizard/Account');
    }

    /**
     * Store the admin user account.
     */
    public function storeAccount(Request $request)
    {
        if (!$this->shouldRun()) {
            return redirect('/');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'The name field is required.',
            'email.required' => 'The email field is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email is already registered.',
            'password.required' => 'The password field is required.',
            'password.min' => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => true,
        ]);

        Auth::login($user);

        return redirect('/wizard/bind-user');
    }

    /**
     * Show the bind user step (link to Linux user).
     */
    public function bindUser()
    {
        $user = Auth::user();

        // If user already has a username bound, skip this step
        if ($user && $user->username) {
            return redirect('/')->with('success', 'Welcome to NovaNAS!');
        }

        $linuxUsers = $this->linuxUserService->listUsers();

        return inertia('Wizard/BindUser', [
            'linuxUsers' => $linuxUsers,
        ]);
    }

    /**
     * Store the Linux user binding.
     */
    public function storeBindUser(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return redirect('/wizard');
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]*$/i', 'max:32'],
        ], [
            'username.required' => 'The username field is required.',
            'username.regex' => 'Please enter a valid Linux username (letters, numbers, underscores, and hyphens only).',
            'username.max' => 'The username must not exceed 32 characters.',
        ]);

        // Verify the Linux user exists using the service
        if (!$this->linuxUserService->userExists($validated['username'])) {
            return back()->withErrors(['username' => 'This Linux user does not exist on the system.']);
        }

        // Check if username is already taken by another user
        $existingUser = User::where('username', $validated['username'])->where('id', '!=', $user->id)->first();

        if ($existingUser) {
            return back()->withErrors(['username' => 'This username is already bound to another account.']);
        }

        $user->username = $validated['username'];
        $user->save();

        return redirect('/')->with('success', 'Welcome to NovaNAS!');
    }

    /**
     * Skip the wizard (for development purposes).
     */
    public function skip()
    {
        if (!$this->shouldRun()) {
            return redirect('/');
        }

        // Create a default admin user for testing
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@novanas.local',
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);

        Auth::login($user);

        return redirect('/');
    }
}
