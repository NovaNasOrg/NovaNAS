<?php

namespace App\Contracts;

/**
 * Interface for DynDNS providers.
 *
 * Each provider must implement this interface to be supported by the DynDNS system.
 */
interface DynDNSProviderInterface
{
    /**
     * Get the provider name/identifier.
     */
    public function getProviderName(): string;

    /**
     * Get the display name for the provider.
     */
    public function getDisplayName(): string;

    /**
     * Get the required configuration fields for this provider.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, placeholder?: string}>
     */
    public function getRequiredFields(): array;

    /**
     * Register a new DNS record (optional - not all providers support this).
     *
     * @param array{subdomain: string} $config
     * @return array{success: bool, token?: string, message: string}
     */
    public function register(array $config): array;

    /**
     * Update the DNS record.
     *
     * @param array{subdomain: string, token: string} $config The provider-specific configuration
     * @return array{success: bool, ip?: string, message: string}
     */
    public function update(array $config): array;

    /**
     * Delete the DNS record.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array{success: bool, message: string}
     */
    public function delete(array $config): array;

    /**
     * Get the base URL for the provider's API.
     */
    public function getBaseUrl(): string;

    /**
     * Build the query parameters for the update request.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array<string, string>
     */
    public function buildQueryParams(array $config): array;

    /**
     * Get info about the DynDNS service.
     *
     * @return array{max_subdomains: int, domain: string}
     */
    public function getInfo(): array;
}
