<?php

namespace App\Services\DynDNS;

use App\Contracts\DynDNSProviderInterface;
use App\Services\DynDNS\Providers\DuckDNSProvider;
use App\Services\DynDNS\Providers\NovaNASProvider;
use Illuminate\Support\Manager;

/**
 * Manager for DynDNS providers.
 *
 * This class handles provider registration and resolution.
 */
class DynDNSProviderManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'novanas';
    }

    /**
     * Create the NovaNAS driver.
     */
    protected function createNovanasDriver(): DynDNSProviderInterface
    {
        return new NovaNASProvider();
    }

    /**
     * Create the DuckDNS driver.
     */
    protected function createDuckdnsDriver(): DynDNSProviderInterface
    {
        return new DuckDNSProvider();
    }

    /**
     * Register a custom provider.
     */
    public function registerProvider(string $name, callable $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Get a provider by name.
     */
    public function getProvider(string $name): DynDNSProviderInterface
    {
        return $this->driver($name);
    }

    /**
     * Get all available provider names.
     *
     * @return array<string, string>
     */
    public function getAvailableProviders(): array
    {
        return [
            'novanas' => 'NovaNAS',
            'duckdns' => 'DuckDNS',
        ];
    }

    /**
     * Get provider required fields.
     */
    public function getProviderFields(string $provider): array
    {
        return $this->getProvider($provider)->getRequiredFields();
    }
}
