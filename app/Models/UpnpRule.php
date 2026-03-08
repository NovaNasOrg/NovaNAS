<?php

namespace App\Models;

use App\Services\Firewall\UfwService;
use App\Services\NetworkService;
use Illuminate\Database\Eloquent\Model;

/**
 * UPNP Port Mapping Rule Model.
 *
 * Represents a UPNP port forwarding rule stored in the database.
 * These rules are temporary (lease-based) and need to be renewed periodically.
 */
class UpnpRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'interface',
        'external_port',
        'internal_port',
        'protocol',
        'description',
        'is_enabled',
        'remote_host',
        'last_renewed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'external_port' => 'integer',
            'internal_port' => 'integer',
            'last_renewed_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to only include enabled rules.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Get the internal IP address from the selected interface.
     */
    public function getInternalIp(): ?string
    {
        $interface = $this->interface;

        if (empty($interface)) {
            return null;
        }

        $result = shell_exec("ip -4 addr show {$interface} 2>/dev/null");

        if ($result && preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $result, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Ensure UPnP discovery port (UDP 1900) is allowed in the firewall.
     * This is required for miniupnpc to discover IGD devices on the network.
     */
    protected function ensureUpnpPortAllowed(): void
    {
        $ufwService = new UfwService();
        $networkService = new NetworkService();

        // Get the gateway IP (router IP)
        $gatewayIp = $networkService->getGatewayIp();

        if (!$gatewayIp) {
            \Illuminate\Support\Facades\Log::warning("UpnpRenewJob: Could not determine gateway IP");

            return;
        }

        // Check if there's already a rule allowing all traffic from the gateway
        $rules = $ufwService->getRules();
        $gatewayRuleExists = false;

        foreach ($rules as $rule) {
            // Check for a rule that allows traffic from the gateway IP
            if (
                $rule['from'] === $gatewayIp &&
                strtoupper($rule['action']) === 'ALLOW'
            ) {
                $gatewayRuleExists = true;

                break;
            }
        }

        if (!$gatewayRuleExists) {
            \Illuminate\Support\Facades\Log::info("UpnpRenewJob: Adding UFW rule to allow all traffic from gateway {$gatewayIp}");

            // Allow all traffic from the gateway IP (router) to handle UPnP responses on any port
            $ufwService->addRule([
                'action' => 'allow',
                'direction' => 'in',
                'from' => $gatewayIp,
                'to' => 'any',
                'comment' => 'UPnP router (allows responses on any port)',
            ]);
        }
    }

    /**
     * Publish this rule to the router via UPNP.
     */
    public function publish(): array
    {
        // Ensure UPnP discovery port (UDP 1900) is allowed before attempting UPnP
        $this->ensureUpnpPortAllowed();

        $internalIp = $this->getInternalIp();

        if (!$internalIp) {
            return [
                'success' => false,
                'message' => "Cannot get IP address for interface: {$this->interface}",
            ];
        }

        // Use miniupnpc with sudo to add port mapping
        // upnpc -a <internal_ip> <internal_port> <external_port> <protocol> [duration]
        // Duration is in seconds (3600 = 1 hour) - renewal happens every 30 minutes
        // Use -p to specify local port 12350 (must be allowed in firewall for router responses)
        $command = sprintf(
            'sudo upnpc -a %s %d %d %s -p 12350 3600 2>&1',
            $internalIp,
            $this->internal_port,
            $this->external_port,
            $this->protocol
        );

        // Debug: Log the exact command being executed
        \Illuminate\Support\Facades\Log::info("UpnpRenewJob: Executing command: {$command}");

        $output = shell_exec($command);

        // Debug: Log the raw output
        \Illuminate\Support\Facades\Log::info("UpnpRenewJob: Command output: {$output}");

        if (str_contains($output, 'is redirected to')) {
            $this->update(['last_renewed_at' => now()]);

            return [
                'success' => true,
                'message' => "Port {$this->external_port} ({$this->protocol}) mapped to {$internalIp}:{$this->internal_port}",
            ];
        }

        if (str_contains($output, 'failed') || str_contains($output, 'error') || str_contains($output, 'Failed')) {
            return [
                'success' => false,
                'message' => "UPNP error: {$output}",
            ];
        }

        return [
            'success' => false,
            'message' => "Failed to add port mapping: {$output}",
        ];
    }

    /**
     * Remove this rule from the router via UPNP.
     */
    public function unpublish(): array
    {
        // Use miniupnpc with sudo to remove port mapping
        // upnpc -d <external_port> <protocol>
        $command = sprintf(
            'sudo upnpc -d %d %s 2>&1',
            $this->external_port,
            $this->protocol
        );

        $output = shell_exec($command);

        if (str_contains($output, 'deleted') || str_contains($output, 'removed')) {
            return [
                'success' => true,
                'message' => "Port mapping {$this->external_port}/{$this->protocol} removed",
            ];
        }

        if (str_contains($output, 'failed') || str_contains($output, 'error') || str_contains($output, 'Failed')) {
            return [
                'success' => false,
                'message' => "UPNP error: {$output}",
            ];
        }

        return [
            'success' => false,
            'message' => "Failed to remove port mapping: {$output}",
        ];
    }
}
