<?php

namespace Database\Factories;

use App\Models\ProxyAuthToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyAuthToken>
 */
class ProxyAuthTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => bin2hex(random_bytes(32)),
            'session_id' => null,
            'expires_at' => now()->addDays(30),
        ];
    }
}
