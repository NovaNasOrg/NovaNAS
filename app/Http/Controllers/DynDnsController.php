<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDynDnsConfigRequest;
use App\Http\Requests\UpdateDynDnsConfigRequest;
use App\Models\DynDnsConfig;
use App\Services\DynDNS\DynDNSProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for managing DynDNS configurations.
 */
class DynDnsController extends Controller
{
    /**
     * The provider manager instance.
     */
    protected DynDNSProviderManager $providerManager;

    /**
     * Create a new controller instance.
     */
    public function __construct(DynDNSProviderManager $providerManager)
    {
        $this->providerManager = $providerManager;
    }

    /**
     * Get all DynDNS configurations.
     */
    public function index(): JsonResponse
    {
        $configs = DynDnsConfig::orderBy('created_at', 'desc')->get();

        return response()->json([
            'configs' => $configs->map(fn ($config) => $this->formatConfig($config)),
            'available_providers' => $this->getAvailableProviders(),
        ]);
    }

    /**
     * Store a new DynDNS configuration.
     */
    public function store(StoreDynDnsConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Always use NovaNAS provider with fixed 5-minute interval
        $config = DynDnsConfig::create([
            'provider' => 'novanas',
            'name' => $validated['name'],
            'subdomain' => $validated['subdomain'],
            'token' => '', // Will be set during registration
            'interval_minutes' => 5,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        // Register the subdomain with NovaNAS
        $registrationResult = $config->registerDns();

        if (!$registrationResult['success']) {
            // Delete the config if registration failed
            $config->delete();

            $statusCode = $registrationResult['error_code'] ?? 400;

            return response()->json([
                'message' => $registrationResult['message'],
            ], $statusCode);
        }

        return response()->json([
            'message' => 'DynDNS configuration created successfully.',
            'config' => $this->formatConfig($config->fresh()),
        ], 201);
    }

    /**
     * Update an existing DynDNS configuration.
     */
    public function update(UpdateDynDnsConfigRequest $request, int $id): JsonResponse
    {
        $config = DynDnsConfig::findOrFail($id);

        $validated = $request->validated();

        // Track what fields are being changed and store old values before update
        $changes = [];
        $oldSubdomain = $config->subdomain;

        // Only update token if provided (not empty)
        if (empty($validated['token'])) {
            unset($validated['token']);
        }

        // Track subdomain change (compare with old value)
        if (isset($validated['subdomain']) && $validated['subdomain'] !== $oldSubdomain) {
            $changes['subdomain'] = $validated['subdomain'];
        }

        // Update local database
        $config->update($validated);

        // Sync changes to remote provider if there are changes to sync
        if (!empty($changes)) {
            $syncResult = $config->syncConfig($oldSubdomain, $changes);

            if (!$syncResult['success']) {
                return response()->json([
                    'message' => 'Failed to sync configuration with remote provider: ' . $syncResult['message'],
                ], 400);
            }
        }

        return response()->json([
            'message' => 'DynDNS configuration updated successfully.',
            'config' => $this->formatConfig($config->fresh()),
        ]);
    }

    /**
     * Delete a DynDNS configuration.
     */
    public function destroy(int $id): JsonResponse
    {
        $config = DynDnsConfig::findOrFail($id);

        // Delete the DNS record from the remote provider first
        $deleteResult = $config->deleteDns();

        // Proceed with local deletion regardless of remote delete result
        // This ensures local cleanup happens even if remote fails
        $config->delete();

        return response()->json([
            'message' => 'DynDNS configuration deleted successfully.',
        ]);
    }

    /**
     * Trigger an immediate update for a specific configuration.
     */
    public function updateNow(int $id): JsonResponse
    {
        $config = DynDnsConfig::findOrFail($id);

        $result = $config->updateDns();

        if ($result['success']) {
            $config->refresh();

            return response()->json([
                'message' => $result['message'],
                'config' => $this->formatConfig($config),
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'config' => $this->formatConfig($config),
        ], 500);
    }

    /**
     * Trigger updates for all enabled configurations.
     */
    public function updateAll(): JsonResponse
    {
        $configs = DynDnsConfig::enabled()->get();

        if ($configs->isEmpty()) {
            return response()->json([
                'message' => 'No enabled DynDNS configurations found.',
                'results' => [],
            ]);
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($configs as $config) {
            $result = $config->updateDns();
            $results[] = [
                'id' => $config->id,
                'name' => $config->name,
                'success' => $result['success'],
                'message' => $result['message'],
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }

            $config->refresh();
        }

        return response()->json([
            'message' => "Update complete: {$successCount} success, {$failureCount} failure(s).",
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ]);
    }

    /**
     * Get provider fields for the frontend form.
     */
    public function getProviderFields(Request $request): JsonResponse
    {
        $provider = $request->input('provider', 'novanas');

        try {
            $fields = $this->providerManager->getProviderFields($provider);

            return response()->json([
                'fields' => $fields,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Provider not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get available providers.
     *
     * @return array<int, array{key: string, name: string}>
     */
    protected function getAvailableProviders(): array
    {
        return [
            ['key' => 'novanas', 'name' => 'NovaNAS'],
        ];
    }

    /**
     * Format a config for JSON response.
     *
     * @return array<string, mixed>
     */
    protected function formatConfig(DynDnsConfig $config): array
    {
        $providerInstance = $config->getProviderInstance();

        return [
            'id' => $config->id,
            'provider' => $config->provider,
            'provider_display_name' => $providerInstance->getDisplayName(),
            'name' => $config->name,
            'subdomain' => $config->subdomain,
            'full_domain' => $config->full_domain,
            'interval_minutes' => $config->interval_minutes,
            'is_enabled' => $config->is_enabled,
            'last_updated_at' => $config->last_updated_at?->toIso8601String(),
            'last_ip' => $config->last_ip,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }
}
