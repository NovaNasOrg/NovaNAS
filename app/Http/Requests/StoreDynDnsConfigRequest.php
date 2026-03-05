<?php

namespace App\Http\Requests;

use App\Rules\SubdomainRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDynDnsConfigRequest extends FormRequest
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
        $provider = $this->input('provider', 'novanas');

        // For NovaNAS, token is not required on creation (it comes from the API)
        // For DuckDNS, token is required
        $tokenRule = $provider === 'novanas' ? 'nullable|string' : 'required|string';

        return [
            'provider' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'subdomain' => [
                'required',
                'string',
                new SubdomainRule(),
            ],
            'token' => $tokenRule,
            'interval_minutes' => 'required|integer|min:1|max:1440',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Please select a provider.',
            'name.required' => 'Please enter a name for this configuration.',
            'subdomain.required' => 'Please enter your subdomain.',
            'token.required' => 'Please enter your API token.',
            'interval_minutes.required' => 'Please specify the update interval.',
            'interval_minutes.min' => 'Interval must be at least 1 minute.',
            'interval_minutes.max' => 'Interval cannot exceed 1440 minutes (24 hours).',
        ];
    }
}
