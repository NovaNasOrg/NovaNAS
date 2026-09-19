<?php

namespace Database\Factories;

use App\Models\DesktopDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DesktopDevice> */
class DesktopDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'name' => fake()->word().' computer',
            'platform' => 'darwin',
            'samba_username' => 'nvd'.Str::lower(Str::random(12)),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }
}
