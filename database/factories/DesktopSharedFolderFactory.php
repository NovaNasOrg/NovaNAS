<?php

namespace Database\Factories;

use App\Models\DesktopSharedFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DesktopSharedFolder> */
class DesktopSharedFolderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'samba_name' => fake()->unique()->slug(2),
            'path' => '/srv/'.fake()->unique()->slug(),
            'type' => 'custom',
        ];
    }
}
