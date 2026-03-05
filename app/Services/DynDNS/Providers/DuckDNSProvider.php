<?php

namespace App\Services\DynDNS\Providers;

use App\Contracts\DynDNSProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DuckDNS provider implementation.
 *
 * @see https://www.duckdns.org/specifications.jsp
 */
class DuckDNSProvider implements DynDNSProviderInterface
{
    /**
     * Get the provider name/identifier.
     */
    public function getProviderName(): string
    {
        return 'duckdns';
    }

    /**
     * Get the display name for the provider.
     */
    public function getDisplayName(): string
    {
        return 'DuckDNS';
    }

    /**
     * Get the required configuration fields for this provider.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, placeholder?: string}>
     */
    public function getRequiredFields(): array
    {
        return [
            [
                'key' => 'subdomain',
                'label' => 'Subdomain',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'yourdomain (without .duckdns.org)',
            ],
            [
                'key' => 'token',
                'label' => 'Token',
                'type' => 'password',
                'required' => true,
                'placeholder' => 'Your DuckDNS token',
            ],
        ];
    }

    /**
     * Register a new DNS record (not supported by DuckDNS).
     *
     * @param array{subdomain: string} $config
     * @return array{success: bool, message: string}
     */
    public function register(array $config): array
    {
        // DuckDNS doesn't require registration - token is provided by user
        return [
            'success' => false,
            'message' => 'DuckDNS does not require registration. Please provide your token.',
        ];
    }

    /**
     * Update the DNS record.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array{success: bool, ip?: string, message: string}
     */
    public function update(array $config): array
    {
        $url = $this->getBaseUrl();
        $queryParams = $this->buildQueryParams($config);

        try {
            $response = Http::get($url, $queryParams);
            $body = trim($response->body());

            Log::info('DuckDNS update response', [
                'subdomain' => $config['subdomain'],
                'response' => $body,
                'status' => $response->status(),
            ]);

            // DuckDNS returns "OK" on success, "KO" on failure
            if ($body === 'OK') {
                return [
                    'success' => true,
                    'message' => 'DNS record updated successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Update failed: ' . $body,
            ];
        } catch (\Exception $e) {
            Log::error('DuckDNS update failed', [
                'subdomain' => $config['subdomain'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete is not supported for DuckDNS (no API for deletion).
     *
     * @param array{subdomain: string, token: string} $config
     * @return array{success: bool, message: string}
     */
    public function delete(array $config): array
    {
        return [
            'success' => false,
            'message' => 'DuckDNS does not support deletion via API.',
        ];
    }

    /**
     * Get the base URL for the provider's API.
     */
    public function getBaseUrl(): string
    {
        return 'https://www.duckdns.org/update';
    }

    /**
     * Build the query parameters for the update request.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array<string, string>
     */
    public function buildQueryParams(array $config): array
    {
        return [
            'domains' => $config['subdomain'],
            'token' => $config['token'],
        ];
    }

    /**
     * Get info about the DynDNS service.
     *
     * @return array{max_subdomains: int, domain: string}
     */
    public function getInfo(): array
    {
        return [
            'max_subdomains' => 5, // DuckDNS allows 5 subdomains per account
            'domain' => 'duckdns.org',
        ];
    }
}
