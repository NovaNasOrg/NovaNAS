<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFirewallRuleRequest;
use App\Services\Firewall\UfwService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for managing UFW firewall rules.
 */
class FirewallController extends Controller
{
    public function __construct(public UfwService $ufwService)
    {
    }

    /**
     * Get firewall status and rules.
     */
    public function index(): JsonResponse
    {
        $status = $this->ufwService->getStatus();
        $rules = $this->ufwService->getRules();

        return response()->json([
            'status' => $status['status'],
            'active' => $status['active'],
            'rules' => $rules,
        ]);
    }

    /**
     * Get firewall status.
     */
    public function status(): JsonResponse
    {
        $status = $this->ufwService->getStatus();

        return response()->json($status);
    }

    /**
     * Enable the firewall.
     */
    public function enable(): JsonResponse
    {
        // Then enable the firewall
        $result = $this->ufwService->enable();

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'active' => true,
                'status' => 'active',
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'error' => true,
        ], 500);
    }

    /**
     * Disable the firewall.
     */
    public function disable(): JsonResponse
    {
        $result = $this->ufwService->disable();

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'active' => false,
                'status' => 'inactive',
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'error' => true,
        ], 500);
    }

    /**
     * Get all rules.
     */
    public function rules(): JsonResponse
    {
        $rules = $this->ufwService->getRules();

        return response()->json([
            'rules' => $rules,
        ]);
    }

    /**
     * Add a new rule.
     */
    public function store(StoreFirewallRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // New rules get lowest priority (added to end)
        $result = $this->ufwService->addRule([
            'action' => $validated['action'],
            'direction' => $validated['direction'] ?? 'IN',
            'port' => $validated['port'],
            'protocol' => $validated['protocol'],
            'from' => $validated['from'] ?? 'any',
            'to' => $validated['to'] ?? 'any',
            'interface' => $validated['interface'] ?? null,
            'comment' => $validated['comment'] ?? null,
        ]);

        if ($result['success']) {
            // Refresh rules to get the new rule with its priority
            $rules = $this->ufwService->getRules();

            return response()->json([
                'message' => 'Rule added successfully',
                'rules' => $rules,
            ], 201);
        }

        return response()->json([
            'message' => $result['message'],
            'error' => true,
        ], 500);
    }

    /**
     * Delete a rule by ID (priority number).
     */
    public function destroy(int $id): JsonResponse
    {
        $result = $this->ufwService->deleteRule($id);

        if ($result['success']) {
            $rules = $this->ufwService->getRules();

            return response()->json([
                'message' => 'Rule deleted successfully',
                'rules' => $rules,
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'error' => true,
        ], 500);
    }

    /**
     * Update a rule - delete old and insert new at the same position.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:allow,deny,reject,drop'],
            'direction' => ['nullable', 'string', 'in:IN,OUT'],
            'port' => ['nullable', 'string', 'max:50'],
            'protocol' => ['required', 'string', 'in:TCP,UDP,any'],
            'from' => ['nullable', 'string', 'max:45'],
            'to' => ['nullable', 'string', 'max:45'],
            'interface' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:200'],
        ]);

        $priority = $id; // The rule ID is its priority/position

        // First delete the old rule
        $deleteResult = $this->ufwService->deleteRule($priority);
        if (!$deleteResult['success']) {
            return response()->json([
                'message' => 'Failed to delete old rule: ' . $deleteResult['message'],
                'error' => true,
            ], 500);
        }

        // Small delay to let UFW process the deletion
        usleep(500000); // 500ms

        // Re-add the rule at the same position using insertRule
        $ruleData = [
            'action' => $request->input('action'),
            'direction' => $request->input('direction') ?? 'IN',
            'port' => $request->input('port'),
            'protocol' => $request->input('protocol') ?? 'any',
            'from' => $request->input('from') ?? 'any',
            'to' => $request->input('to') ?? 'any',
            'interface' => $request->input('interface'),
            'comment' => $request->input('comment'),
        ];

        $insertResult = $this->ufwService->insertRule($priority, $ruleData);

        if ($insertResult['success']) {
            $rules = $this->ufwService->getRules();

            return response()->json([
                'message' => 'Rule updated successfully',
                'rules' => $rules,
            ]);
        }

        // If insert failed, try to add it at the end
        $addResult = $this->ufwService->addRule($ruleData);

        return response()->json([
            'message' => $addResult['success']
                ? 'Rule updated but position changed: ' . $addResult['message']
                : 'Failed to update rule: ' . ($insertResult['message'] ?? 'Unknown error'),
            'error' => !$addResult['success'],
            'rules' => $this->ufwService->getRules(),
        ], $addResult['success'] ? 200 : 500);
    }

    /**
     * Reorder a rule - move to a new priority position.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'from_priority' => ['required', 'integer', 'min:1'],
            'to_priority' => ['required', 'integer', 'min:1'],
        ]);

        $fromPriority = $request->input('from_priority');
        $toPriority = $request->input('to_priority');

        if ($fromPriority === $toPriority) {
            return response()->json([
                'message' => 'Rule is already at this position',
            ]);
        }

        // Get all current rules
        $rules = $this->ufwService->getRules();

        // Find the rule we're moving
        $ruleToMove = null;
        foreach ($rules as $rule) {
            if ($rule['priority'] === $fromPriority) {
                $ruleToMove = $rule;
                break;
            }
        }

        if (!$ruleToMove) {
            return response()->json([
                'message' => 'Rule not found at specified position',
            ], 404);
        }

        // Delete the rule from its current position
        $deleteResult = $this->ufwService->deleteRule($fromPriority);
        if (!$deleteResult['success']) {
            return response()->json([
                'message' => 'Failed to remove rule from original position: ' . $deleteResult['message'],
            ], 500);
        }

        // Re-fetch rules after deletion (indices have shifted)
        $rulesAfterDelete = $this->ufwService->getRules();
        $currentCount = count($rulesAfterDelete);

        // Prepare rule data
        $ruleData = [
            'action' => $ruleToMove['action'],
            'direction' => $ruleToMove['direction'] ?? 'IN',
            'port' => $ruleToMove['port'],
            'protocol' => $ruleToMove['protocol'],
            'from' => $ruleToMove['from'],
            'to' => $ruleToMove['to'],
            'interface' => $ruleToMove['interface'] ?? null,
            'comment' => $ruleToMove['comment'] ?? null,
        ];

        // If moving to a position beyond current rules, use addRule (appends to end)
        // Otherwise use insertRule at the calculated position
        $insertResult = null;
        if ($toPriority > $currentCount) {
            // Target position is beyond current rules, just add to end
            $insertResult = $this->ufwService->addRule($ruleData);
        } else {
            // Calculate actual insert position
            $insertPosition = $toPriority;
            if ($fromPriority < $toPriority) {
                // Moving down: after deletion, indices shift down by 1
                $insertPosition = $toPriority;
            }
            $insertResult = $this->ufwService->insertRule($insertPosition, $ruleData);

            // If insert failed, try adding at the end
            if (!$insertResult['success']) {
                $insertResult = $this->ufwService->addRule($ruleData);
            }
        }

        if ($insertResult['success']) {
            $finalRules = $this->ufwService->getRules();

            return response()->json([
                'message' => 'Rule reordered successfully',
                'rules' => $finalRules,
            ]);
        }

        // If insert failed, try to add it back at original position
        $this->ufwService->addRule([
            'action' => $ruleToMove['action'],
            'direction' => $ruleToMove['direction'] ?? 'IN',
            'port' => $ruleToMove['port'],
            'protocol' => $ruleToMove['protocol'],
            'from' => $ruleToMove['from'],
            'to' => $ruleToMove['to'],
            'interface' => $ruleToMove['interface'] ?? null,
            'comment' => $ruleToMove['comment'] ?? null,
        ]);

        return response()->json([
            'message' => 'Failed to reorder rule: ' . $insertResult['message'],
            'error' => true,
        ], 500);
    }

    /**
     * Get available network interfaces.
     */
    public function interfaces(): JsonResponse
    {
        $interfaces = $this->ufwService->getInterfaces();

        return response()->json($interfaces);
    }

    /**
     * Get default firewall policies.
     */
    public function defaultPolicies(): JsonResponse
    {
        $policies = $this->ufwService->getDefaultPolicies();

        return response()->json($policies);
    }

    /**
     * Set default firewall policy.
     */
    public function setDefaultPolicy(Request $request): JsonResponse
    {
        $request->validate([
            'direction' => ['required', 'string', 'in:incoming,outgoing,routed'],
            'policy' => ['required', 'string', 'in:allow,deny,reject'],
        ]);

        $direction = $request->input('direction');
        $policy = $request->input('policy');

        $result = $this->ufwService->setDefaultPolicy($direction, $policy);

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'policies' => $this->ufwService->getDefaultPolicies(),
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'error' => true,
        ], 500);
    }
}
