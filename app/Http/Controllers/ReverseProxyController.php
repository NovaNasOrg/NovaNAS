<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProxyHostRequest;
use App\Http\Requests\UpdateProxyHostRequest;
use App\Models\ProxyHost;
use App\Services\ReverseProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for managing Apache reverse proxy hosts.
 */
class ReverseProxyController extends Controller
{
    public function __construct(private ReverseProxyService $reverseProxyService) {}

    /**
     * Get all proxy hosts.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'hosts' => $this->reverseProxyService->getHosts(),
        ]);
    }

    /**
     * Add a new proxy host.
     */
    public function store(StoreProxyHostRequest $request): JsonResponse
    {
        $result = $this->reverseProxyService->createHost($request->validated());

        $response = ['message' => $result['message']];

        if ($result['success']) {
            $response['host'] = $result['host'];

            return response()->json($response, 201);
        }

        return response()->json($response, $result['status']);
    }

    /**
     * Update a proxy host.
     */
    public function update(UpdateProxyHostRequest $request, ProxyHost $host): JsonResponse
    {
        $result = $this->reverseProxyService->updateHost($host, $request->validated());

        $response = ['message' => $result['message']];

        if ($result['success']) {
            $response['host'] = $result['host'];

            return response()->json($response);
        }

        return response()->json($response, $result['status']);
    }

    /**
     * Delete a proxy host.
     */
    public function destroy(ProxyHost $host): JsonResponse
    {
        $result = $this->reverseProxyService->deleteHost($host);

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : $result['status']);
    }

    /**
     * Enable or disable a proxy host.
     */
    public function toggle(Request $request, ProxyHost $host): JsonResponse
    {
        $enabled = $request->boolean('enabled');

        $result = $this->reverseProxyService->toggleHost($host, $enabled);

        $response = ['message' => $result['message']];

        if ($result['success']) {
            $response['host'] = $result['host'];

            return response()->json($response);
        }

        return response()->json($response, $result['status']);
    }

    /**
     * Request a Let's Encrypt certificate for a proxy host.
     */
    public function issueLetsEncrypt(ProxyHost $host): JsonResponse
    {
        $result = $this->reverseProxyService->issueLetsEncrypt($host);

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : $result['status']);
    }

    /**
     * Generate a self-signed certificate for a proxy host.
     */
    public function generateSelfSigned(ProxyHost $host): JsonResponse
    {
        $result = $this->reverseProxyService->generateSelfSigned($host);

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : $result['status']);
    }

    /**
     * Install a custom certificate for a proxy host.
     */
    public function installCustomCertificate(Request $request, ProxyHost $host): JsonResponse
    {
        $validated = $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
            'ca_bundle' => 'nullable|string',
        ]);

        $result = $this->reverseProxyService->installCustomCertificate(
            $host,
            $validated['certificate'],
            $validated['private_key'],
            $validated['ca_bundle'] ?? null
        );

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : $result['status']);
    }

    /**
     * Remove the certificate of a proxy host.
     */
    public function removeCertificate(ProxyHost $host): JsonResponse
    {
        $result = $this->reverseProxyService->removeCertificate($host);

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : $result['status']);
    }

    /**
     * Check whether a domain is reachable from the internet.
     */
    public function checkReachability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:253',
        ]);

        return response()->json(
            $this->reverseProxyService->checkDomainReachability($validated['domain'])
        );
    }
}
