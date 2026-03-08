<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation request for firewall rule storage.
 */
class StoreFirewallRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:allow,deny,reject,drop'],
            'port' => ['nullable', 'string', 'max:50'],
            'protocol' => ['required', 'string', 'in:TCP,UDP,any'],
            'from' => ['nullable', 'string', 'max:45'],
            'to' => ['nullable', 'string', 'max:45'],
            'interface' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:200'],
            'direction' => ['nullable', 'string', 'in:IN,OUT'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Please select an action (allow, deny, reject, or drop).',
            'action.in' => 'The action must be one of: allow, deny, reject, or drop.',
            'port.max' => 'The port may not be greater than 50 characters.',
            'protocol.required' => 'Please select a protocol.',
            'protocol.in' => 'The protocol must be TCP, UDP, or any.',
            'from.max' => 'The source address may not be greater than 45 characters.',
            'to.max' => 'The destination address may not be greater than 45 characters.',
            'interface.max' => 'The interface name may not be greater than 20 characters.',
            'comment.max' => 'The comment may not be greater than 200 characters.',
            'direction.in' => 'The direction must be IN or OUT.',
        ];
    }
}
