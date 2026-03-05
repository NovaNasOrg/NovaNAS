<?php

namespace App\Services\DynDNS\Providers;

use App\Contracts\DynDNSProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NovaNAS DynDNS provider implementation.
 *
 * Uses the NovaNAS API for dynamic DNS management.
 */
class NovaNASProvider implements DynDNSProviderInterface
{
    /**
     * Get the provider name/identifier.
     */
    public function getProviderName(): string
    {
        return 'novanas';
    }

    /**
     * Get the display name for the provider.
     */
    public function getDisplayName(): string
    {
        return 'NovaNAS';
    }

    /**
     * Get the required configuration fields for this provider.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, placeholder?: string}>
     */
    public function getRequiredFields(): array
    {
        $baseDomain = config('services.novanas.base_domain', 'novanas.org');

        return [
            [
                'key' => 'subdomain',
                'label' => 'Subdomain',
                'type' => 'text',
                'required' => true,
                'placeholder' => "yourdomain (without .{$baseDomain})",
            ],
        ];
    }

    /**
     * Get the base domain from config.
     */
    public function getBaseDomain(): string
    {
        return config('services.novanas.base_domain', 'novanas.org');
    }

    /**
     * Register a new DNS record with NovaNAS API.
     *
     * @param array{subdomain: string} $config
     * @return array{success: bool, token?: string, message: string}
     */
    public function register(array $config): array
    {
        $url = $this->getRegisterUrl();

        try {
            $response = Http::post($url, [
                'subdomain' => $config['subdomain'],
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('NovaNAS DNS record registered', [
                    'subdomain' => $config['subdomain'],
                    'full_domain' => $data['data']['full_domain'] ?? $config['subdomain'] . '.' . $this->getBaseDomain(),
                ]);

                return [
                    'success' => true,
                    'token' => $data['data']['token'],
                    'full_domain' => $data['data']['full_domain'],
                    'ip_address' => $data['data']['ip_address'] ?? null,
                    'message' => $data['message'] ?? 'DNS record created successfully.',
                ];
            }

            $status = $response->status();
            $body = $response->json();

            if ($status === 403) {
                Log::warning('NovaNAS registration failed: Maximum records reached', [
                    'subdomain' => $config['subdomain'],
                ]);

                return [
                    'success' => false,
                    'message' => 'Maximum number of DNS records reached for this IP address.',
                    'error_code' => 403,
                ];
            }

            if ($status === 409) {
                Log::warning('NovaNAS registration failed: Subdomain exists', [
                    'subdomain' => $config['subdomain'],
                ]);

                return [
                    'success' => false,
                    'message' => 'This subdomain already exists in DNS.',
                    'error_code' => 409,
                ];
            }

            Log::error('NovaNAS registration failed', [
                'subdomain' => $config['subdomain'],
                'status' => $status,
                'response' => $body,
            ]);

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Failed to register DNS record.',
            ];
        } catch (\Exception $e) {
            Log::error('NovaNAS registration exception', [
                'subdomain' => $config['subdomain'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update the DNS record.
     *
     * @param array{subdomain: string, token: string, new_subdomain?: string} $config
     * @return array{success: bool, ip?: string, new_subdomain?: string, message: string}
     */
    public function update(array $config): array
    {
        $url = $this->getUpdateUrl($config['subdomain']);

        try {
            $data = [
                'token' => $config['token'],
            ];

            // Include new subdomain if provided (for config updates)
            if (!empty($config['new_subdomain'])) {
                $data['subdomain'] = $config['new_subdomain'];
            }

            $response = Http::put($url, $data);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('NovaNAS DNS record updated', [
                    'subdomain' => $config['subdomain'],
                    'new_subdomain' => $config['new_subdomain'] ?? null,
                    'ip_address' => $responseData['data']['ip_address'] ?? null,
                ]);

                return [
                    'success' => true,
                    'ip' => $responseData['data']['ip_address'] ?? null,
                    'new_subdomain' => $config['new_subdomain'] ?? null,
                    'message' => $responseData['message'] ?? 'DNS record updated successfully.',
                ];
            }

            $status = $response->status();
            $body = $response->json();

            Log::error('NovaNAS update failed', [
                'subdomain' => $config['subdomain'],
                'status' => $status,
                'response' => $body,
            ]);

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Update failed.',
            ];
        } catch (\Exception $e) {
            Log::error('NovaNAS update exception', [
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
     * Get the base URL for the provider's API.
     */
    public function getBaseUrl(): string
    {
        return rtrim(config('services.novanas.api_url', 'https://api.novanas.org/api'), '/');
    }

    /**
     * Get the registration URL.
     */
    public function getRegisterUrl(): string
    {
        return $this->getBaseUrl() . '/dyndns/register';
    }

    /**
     * Get the update URL for a subdomain.
     */
    public function getUpdateUrl(string $subdomain): string
    {
        return $this->getBaseUrl() . '/dyndns/' . $subdomain;
    }

    /**
     * Get the HTTP method to use for updates.
     */
    public function getHttpMethod(): string
    {
        return 'PUT';
    }

    /**
     * Delete the DNS record.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array{success: bool, message: string}
     */
    public function delete(array $config): array
    {
        $url = $this->getDeleteUrl($config['subdomain']);

        try {
            $response = Http::delete($url, [
                'token' => $config['token'],
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('NovaNAS DNS record deleted', [
                    'subdomain' => $config['subdomain'],
                ]);

                return [
                    'success' => true,
                    'message' => $responseData['message'] ?? 'DNS record deleted successfully.',
                ];
            }

            $status = $response->status();
            $body = $response->json();

            Log::error('NovaNAS delete failed', [
                'subdomain' => $config['subdomain'],
                'status' => $status,
                'response' => $body,
            ]);

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Delete failed.',
            ];
        } catch (\Exception $e) {
            Log::error('NovaNAS delete exception', [
                'subdomain' => $config['subdomain'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Delete failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the delete URL for a subdomain.
     */
    public function getDeleteUrl(string $subdomain): string
    {
        return $this->getBaseUrl() . '/dyndns/' . $subdomain;
    }

    /**
     * Build the query parameters for the update request.
     * Note: NovaNAS uses PUT with JSON body, so this returns empty array.
     *
     * @param array{subdomain: string, token: string} $config
     * @return array<string, string>
     */
    public function buildQueryParams(array $config): array
    {
        // NovaNAS uses PUT with JSON body, not query params
        return [];
    }
}
