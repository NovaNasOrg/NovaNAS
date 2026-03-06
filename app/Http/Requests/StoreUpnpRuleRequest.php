<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation request for UPNP rule storage and updates.
 */
class StoreUpnpRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'interface' => ['required', 'string', 'max:255'],
            'external_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'internal_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', 'in:TCP,UDP'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['boolean'],
            'remote_host' => ['nullable', 'string', 'max:45'],
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
            'name.required' => 'The rule name is required.',
            'name.max' => 'The rule name may not be greater than 255 characters.',
            'interface.required' => 'Please select a network interface.',
            'external_port.required' => 'The external port is required.',
            'external_port.integer' => 'The external port must be a number.',
            'external_port.min' => 'The external port must be at least 1.',
            'external_port.max' => 'The external port may not be greater than 65535.',
            'internal_port.required' => 'The internal port is required.',
            'internal_port.integer' => 'The internal port must be a number.',
            'internal_port.min' => 'The internal port must be at least 1.',
            'internal_port.max' => 'The internal port may not be greater than 65535.',
            'protocol.required' => 'Please select a protocol.',
            'protocol.in' => 'The protocol must be either TCP or UDP.',
        ];
    }
}
