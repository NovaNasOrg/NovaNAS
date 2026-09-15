<?php

namespace Database\Factories;

use App\Models\ProxyHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyHost>
 */
class ProxyHostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => strtolower(fake()->unique()->domainWord().'.'.fake()->domainName()),
            'target_host' => fake()->localIpv4(),
            'target_port' => fake()->numberBetween(1024, 65535),
            'target_protocol' => 'http',
            'websocket_enabled' => false,
            'https_redirect' => false,
            'ssl_mode' => 'none',
            'enabled' => true,
        ];
    }
}
