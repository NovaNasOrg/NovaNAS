<?php

namespace App\Services\Firewall;

use Illuminate\Support\Facades\Process;

/**
 * Service for managing UFW (Uncomplicated Firewall) via shell commands.
 */
class UfwService
{
    /**
     * Get the current status of UFW.
     *
     * @return array{status: string, active: bool}
     */
    public function getStatus(): array
    {
        $output = $this->execute('ufw status verbose');

        // Parse status from output
        if (str_contains($output, 'Status: active')) {
            return [
                'status' => 'active',
                'active' => true,
            ];
        }

        if (str_contains($output, 'Status: inactive')) {
            return [
                'status' => 'inactive',
                'active' => false,
            ];
        }

        return [
            'status' => 'unknown',
            'active' => false,
        ];
    }

    /**
     * Enable UFW firewall.
     *
     * @return array{success: bool, message: string}
     */
    public function enable(): array
    {
        // First, ensure IPV6 is disabled in /etc/default/ufw
        $this->setIpv6No();

        // Restart UFW service to apply the configuration change
        $this->execute('systemctl restart ufw');

        // Then, ensure default policies are set
        $this->execute('ufw default allow incoming');
        $this->execute('ufw default allow outgoing');

        $output = $this->execute('ufw --force enable');

        if (str_contains($output, 'Firewall is active')) {
            return [
                'success' => true,
                'message' => 'Firewall enabled successfully',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to enable firewall: ' . $output,
        ];
    }

    /**
     * Set IPV6=no in /etc/default/ufw.
     *
     * @return void
     */
    protected function setIpv6No(): void
    {
        // Check if IPV6 is already set to no
        $checkOutput = $this->execute('grep -E "^IPV6=no" /etc/default/ufw');

        if (str_contains($checkOutput, 'IPV6=no')) {
            return;
        }

        // Replace IPV6=yes with IPV6=no using sed
        $this->execute('sed -i "s/^IPV6=yes/IPV6=no/" /etc/default/ufw');

        // If IPV6 line doesn't exist, add it
        $checkAgain = $this->execute('grep -E "^IPV6=" /etc/default/ufw');

        if (!str_contains($checkAgain, 'IPV6=')) {
            $this->execute('echo "IPV6=no" >> /etc/default/ufw');
        }
    }

    /**
     * Disable UFW firewall.
     *
     * @return array{success: bool, message: string}
     */
    public function disable(): array
    {
        $output = $this->execute('ufw disable');

        if (str_contains($output, 'Firewall stopped')) {
            return [
                'success' => true,
                'message' => 'Firewall disabled successfully',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to disable firewall: ' . $output,
        ];
    }

    /**
     * Get all firewall rules.
     *
     * @return array<int, array{id: int, priority: int, action: string, port: string, protocol: string, from: string, to: string, comment: string, ip_version: string}>
     */
    public function getRules(): array
    {
        // First try the standard numbered status (works when firewall is active)
        $output = $this->execute('ufw status numbered');
        $rules = $this->parseRules($output);

        // If no rules from status numbered, try ufw show added (works when inactive)
        if (empty($rules)) {
            $output = $this->execute('ufw show added');
            $rules = $this->parseAddedRules($output);
        }

        return $rules;
    }

    /**
     * Parse output from 'ufw show added' command.
     *
     * @return array<int, array{id: int, priority: int, action: string, port: string, protocol: string, from: string, to: string, comment: string, ip_version: string}>
     */
    protected function parseAddedRules(string $output): array
    {
        $rules = [];
        $priority = 1;
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Skip header lines
            if (str_starts_with($line, 'Added user rules')) {
                continue;
            }

            // Parse lines like: ufw allow 22/tcp
            // Or: ufw insert 1 allow 22/tcp
            // Or: ufw allow from 192.168.1.0/24 to any port 80 proto tcp
            if (preg_match('/ufw\s+(?:insert\s+\d+\s+)?(\w+)\s+(.*)/', $line, $matches)) {
                $action = strtoupper($matches[1]);
                $spec = $matches[2];

                // Determine direction (default IN for most rules)
                $direction = 'IN';
                if (str_contains($spec, 'out')) {
                    $direction = 'OUT';
                }

                // Detect IP version from the spec
                $ipVersion = 'ipv4';
                if (str_contains($spec, 'ipv6')) {
                    $ipVersion = 'ipv6';
                }

                $rule = [
                    'id' => $priority,
                    'priority' => $priority,
                    'action' => $action,
                    'direction' => $direction,
                    'port' => '',
                    'protocol' => 'any',
                    'from' => 'any',
                    'to' => 'any',
                    'interface' => '',
                    'comment' => '',
                    'ip_version' => $ipVersion,
                ];

                // Parse port/protocol (e.g., "22/tcp", "80:90/udp")
                if (preg_match('/(\d+)(?:\:(\d+))?\/(\w+)/', $spec, $portMatch)) {
                    $rule['port'] = $portMatch[1];
                    $rule['protocol'] = strtoupper($portMatch[3]);
                } elseif (preg_match('/port\s+(\d+)/', $spec, $portMatch)) {
                    $rule['port'] = $portMatch[1];
                }

                // Parse protocol only
                if (preg_match('/proto\s+(\w+)/', $spec, $protoMatch) && empty($rule['protocol'])) {
                    $rule['protocol'] = strtoupper($protoMatch[1]);
                }

                // Parse from address
                if (preg_match('/from\s+([\d\.\/]+)/', $spec, $fromMatch)) {
                    $rule['from'] = $fromMatch[1];
                }

                // Parse to address
                if (preg_match('/to\s+([\d\.\/]+)/', $spec, $toMatch)) {
                    $rule['to'] = $toMatch[1];
                }

                // Parse interface
                if (preg_match('/on\s+(\w+)/', $spec, $ifaceMatch)) {
                    $rule['interface'] = $ifaceMatch[1];
                }

                // Parse comment (e.g., comment 'plex' or comment "plex")
                if (preg_match("/comment\s+['\"]([^'\"]+)['\"]/", $spec, $commentMatch)) {
                    $rule['comment'] = $commentMatch[1];
                }

                $rules[] = $rule;
                $priority++;
            }
        }

        return $rules;
    }

    /**
     * Add a new rule to UFW.
     *
     * @param array{action: string, direction?: string, port: string, protocol: string, from: string, to: string, interface?: string, comment?: string, ip_version?: string} $rule
     * @return array{success: bool, message: string}
     */
    public function addRule(array $rule): array
    {
        $command = $this->buildRuleCommand($rule);

        $output = $this->execute($command);

        if (str_contains($output, 'Rule added') || str_contains($output, 'Rules updated')) {
            return [
                'success' => true,
                'message' => 'Rule added successfully',
            ];
        }

        // Check for common errors
        if (str_contains($output, 'Could not open')) {
            return [
                'success' => false,
                'message' => 'Failed to add rule: ' . $output,
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to add rule: ' . $output,
        ];
    }

    /**
     * Delete a rule by its number (priority).
     *
     * @param int $ruleNumber The rule number from ufw status numbered (1-based)
     * @return array{success: bool, message: string}
     */
    public function deleteRule(int $ruleNumber): array
    {
        // UFW requires interactive deletion, so we use --force
        $output = $this->execute("sudo ufw --force delete {$ruleNumber}");

        // Check for successful deletion - UFW outputs "Rules updated" when deleting
        if (str_contains($output, 'Rule deleted') || str_contains($output, 'Rules updated')) {
            return [
                'success' => true,
                'message' => 'Rule deleted successfully',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to delete rule: ' . $output,
        ];
    }

    /**
     * Insert a rule at a specific priority (position).
     *
     * @param int $priority The position to insert the rule (1 = top)
     * @param array{action: string, direction?: string, port: string, protocol: string, from: string, to: string, interface?: string, comment?: string, ip_version?: string} $rule
     * @return array{success: bool, message: string}
     */
    public function insertRule(int $priority, array $rule): array
    {
        $command = $this->buildRuleCommand($rule, $priority);
        $output = $this->execute($command);

        // Check for success messages
        if (str_contains($output, 'Rule inserted') ||
            str_contains($output, 'Rule added') ||
            str_contains($output, 'Rules updated') ||
            str_contains($output, 'Skipping')) {
            return [
                'success' => true,
                'message' => 'Rule inserted at position ' . $priority,
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to insert rule: ' . $output,
        ];
    }

    /**
     * Get available network interfaces.
     *
     * @return array<int, string>
     */
    public function getInterfaces(): array
    {
        $output = $this->execute('ls /sys/class/net');
        $interfaces = array_filter(
            explode("\n", trim($output)),
            fn($iface) => !empty($iface) && $iface !== 'lo'
        );

        return array_values($interfaces);
    }

    /**
     * Get default policies for incoming, outgoing, and routed traffic.
     *
     * @return array{incoming: string, outgoing: string, routed: string}
     */
    public function getDefaultPolicies(): array
    {
        $output = $this->execute('ufw status verbose');

        $policies = [
            'incoming' => 'allow',
            'outgoing' => 'allow',
            'routed' => 'allow',
        ];

        // Try to parse the compact format: "Default: deny (incoming), allow (outgoing), deny (routed)"
        if (preg_match('/Default:\s*(\w+)\s*\(incoming\)/i', $output, $matches)) {
            $policies['incoming'] = strtolower($matches[1]);
        }
        if (preg_match('/Default:[^,]*,\s*(\w+)\s*\(outgoing\)/i', $output, $matches)) {
            $policies['outgoing'] = strtolower($matches[1]);
        }
        if (preg_match('/Default:.*?(\w+)\s*\(routed\)/i', $output, $matches)) {
            $policies['routed'] = strtolower($matches[1]);
        }

        // Fallback to the verbose format: "Default incoming policy: deny"
        if (preg_match('/Default incoming policy:\s*(\w+)/i', $output, $matches)) {
            $policies['incoming'] = strtolower($matches[1]);
        }
        if (preg_match('/Default outgoing policy:\s*(\w+)/i', $output, $matches)) {
            $policies['outgoing'] = strtolower($matches[1]);
        }
        if (preg_match('/Default routed policy:\s*(\w+)/i', $output, $matches)) {
            $policies['routed'] = strtolower($matches[1]);
        }

        return $policies;
    }

    /**
     * Set default policy for a direction.
     *
     * @param string $direction 'incoming', 'outgoing', or 'routed'
     * @param string $policy 'allow' or 'deny'
     * @return array{success: bool, message: string}
     */
    public function setDefaultPolicy(string $direction, string $policy): array
    {
        $validDirections = ['incoming', 'outgoing', 'routed'];
        $validPolicies = ['allow', 'deny', 'reject'];

        $direction = strtolower($direction);
        $policy = strtolower($policy);

        if (!in_array($direction, $validDirections)) {
            return [
                'success' => false,
                'message' => 'Invalid direction. Must be: ' . implode(', ', $validDirections),
            ];
        }

        if (!in_array($policy, $validPolicies)) {
            return [
                'success' => false,
                'message' => 'Invalid policy. Must be: ' . implode(', ', $validPolicies),
            ];
        }

        $output = $this->execute("ufw default {$policy} {$direction}");

        // UFW returns "Default incoming policy changed to 'deny'" on success
        if (str_contains($output, "Default {$direction} policy") ||
            str_contains($output, 'policy changed') ||
            str_contains($output, 'Default policy')) {
            return [
                'success' => true,
                'message' => "Default {$direction} policy set to {$policy}",
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to set default policy: ' . $output,
        ];
    }

    /**
     * Execute a UFW command with sudo.
     *
     * @return string
     */
    protected function execute(string $command): string
    {
        // Prepend sudo to all UFW commands
        $sudoCommand = 'sudo ' . $command . ' 2>&1';

        $result = Process::run($sudoCommand);

        return $result->output();
    }

    /**
     * Build a UFW rule command from parameters.
     *
     * @param array{action: string, direction?: string, port: string, protocol: string, from: string, to: string, interface?: string, comment?: string, ip_version?: string} $rule
     * @return string
     */
    protected function buildRuleCommand(array $rule, ?int $insertAt = null): string
    {
        $parts = [];

        // Add insert position if specified
        if ($insertAt !== null) {
            $parts[] = 'ufw insert ' . $insertAt;
        } else {
            // For regular add, action will be added separately below
            $parts[] = 'ufw';
        }

        // Action
        $action = strtolower($rule['action'] ?? 'allow');
        $parts[] = $action;

        // Direction (in/out) - only needed for interface or outgoing rules
        $direction = $rule['direction'] ?? '';
        $directionLower = strtolower($direction);
        $hasInterface = !empty($rule['interface']);
        $hasFrom = !empty($rule['from']) && $rule['from'] !== 'any';
        $hasTo = !empty($rule['to']) && $rule['to'] !== 'any';

        // Only add direction if there's an interface, or it's outgoing, or there are from/to addresses
        if ($directionLower === 'out') {
            $parts[] = $directionLower;
        } elseif ($hasInterface || $hasFrom || $hasTo) {
            // Only add 'in' if there's a reason (interface, from, to)
            $parts[] = 'in';
        }
        // For simple port rules with default 'in', don't add direction at all

        // Interface
        if ($hasInterface) {
            $parts[] = 'on ' . $rule['interface'];
        }

        // Port and Protocol
        $port = $rule['port'] ?? '';
        $protocol = strtolower($rule['protocol'] ?? 'any');

        if (!empty($port)) {
            if ($protocol !== 'any') {
                $parts[] = "{$port}/{$protocol}";
            } else {
                $parts[] = $port;
            }
        } elseif ($protocol !== 'any') {
            $parts[] = "proto {$protocol}";
        }

        // From address
        if (!empty($rule['from']) && $rule['from'] !== 'any') {
            $parts[] = 'from ' . $rule['from'];
        }

        // To address
        if (!empty($rule['to']) && $rule['to'] !== 'any') {
            $parts[] = 'to ' . $rule['to'];
        }

        // Comment
        $comment = $rule['comment'] ?? '';
        if (!empty($comment)) {
            $parts[] = 'comment \'' . addslashes(trim($comment)) . '\'';
        }

        $command = implode(' ', $parts);

        return trim($command);
    }

    /**
     * Parse UFW status numbered output into structured rule array.
     *
     * @return array<int, array{id: int, priority: int, action: string, port: string, protocol: string, from: string, to: string, comment: string, ip_version: string}>
     */
    protected function parseRules(string $output): array
    {
        $rules = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip IPv6 rules - only show IPv4
            if (str_contains($line, '(v6)')) {
                continue;
            }

            // Match lines like: [ 1] 22/tcp                     ALLOW IN    Anywhere
            // Or: [ 2] 22/tcp                     ALLOW IN    Anywhere (v6)
            // Or: [ 5] 32400/tcp                  ALLOW IN    Anywhere                   # plex
            // Or: [ 6] Anywhere                   ALLOW IN    192.168.0.1                # UPnP router
            if (preg_match('/\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY|REJECT|DROP)\s+(IN|OUT)\s+(.+)/', $line, $matches)) {
                $priority = (int) $matches[1];
                $ruleDetails = trim($matches[2]);
                $action = $matches[3];
                $direction = $matches[4];
                $from_and_comment = trim($matches[5]);

                $comment = '';
                if (preg_match('/^(.+?)\s+#\s+(.+)$/', $from_and_comment, $m)) {
                    $from = trim($m[1]);
                    $comment = trim($m[2]);
                } else {
                    $from = $from_and_comment;
                }

                // Parse the rule details
                $rule = $this->parseRuleDetails($ruleDetails, '', $comment);

                $rules[] = [
                    'id' => $priority,
                    'priority' => $priority,
                    'action' => $action,
                    'direction' => $direction,
                    'port' => $rule['port'],
                    'protocol' => $rule['protocol'],
                    'from' => $from === 'Anywhere' ? 'any' : $from,
                    'to' => $rule['to'],
                    'interface' => $rule['interface'],
                    'comment' => $rule['comment'] ?? '',
                ];
            }
        }

        return $rules;
    }

    /**
     * Parse rule details string into components.
     *
     * @param string $details The rule details (e.g., "32400/tcp" or "22/tcp from 192.168.1.0/24")
     * @param string $trailing Optional trailing content after the main rule (may contain comment)
     * @param string $comment Optional comment already extracted
     * @return array{port: string, protocol: string, from: string, to: string, interface: string, comment?: string}
     */
    protected function parseRuleDetails(string $details, string $trailing = '', string $comment = ''): array
    {
        $result = [
            'port' => '',
            'protocol' => 'any',
            'from' => 'any',
            'to' => 'any',
            'interface' => '',
        ];

        // Use provided comment if available, otherwise check trailing content
        if (!empty($comment)) {
            $result['comment'] = $comment;
        } elseif (!empty($trailing) && preg_match('/#\s+(.+)$/', $trailing, $matches)) {
            $result['comment'] = trim($matches[1]);
        }

        // Check for interface (e.g., "on eth0")
        if (preg_match('/on\s+(\S+)/', $details, $matches)) {
            $result['interface'] = $matches[1];
        }

        // Check for port/protocol (e.g., "22/tcp", "80:90/udp")
        if (preg_match('/(\d+:?\d*)\/(\w+)/', $details, $matches)) {
            $result['port'] = $matches[1];
            $result['protocol'] = $matches[2];
        } elseif (preg_match('/^(\d+)$/', trim($details), $matches)) {
            $result['port'] = $matches[1];
        }

        // Check for from address
        if (preg_match('/from\s+(\S+)/', $details, $matches)) {
            $result['from'] = $matches[1];
        }

        // Check for to address
        if (preg_match('/to\s+(\S+)/', $details, $matches)) {
            $result['to'] = $matches[1];
        }

        // Check for comment in format: # comment or (comment) or "comment" or 'comment'
        if (preg_match('/\s+#\s+(.+)$/', $details, $matches)) {
            $result['comment'] = trim($matches[1]);
        } elseif (preg_match('/\s+\((\\S+)\)/', $details, $matches)) {
            $result['comment'] = trim($matches[1], '"\'');
        } elseif (preg_match('/\s+"([^"]+)"/', $details, $matches)) {
            $result['comment'] = $matches[1];
        } elseif (preg_match("/\s+'([^']+)'/", $details, $matches)) {
            $result['comment'] = $matches[1];
        }

        return $result;
    }
}
