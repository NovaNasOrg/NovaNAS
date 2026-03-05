<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDynDnsConfigRequest extends FormRequest
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
            'provider' => 'sometimes|required|string|max:50',
            'name' => 'sometimes|required|string|max:255',
            'subdomain' => 'sometimes|required|string|max:255',
            'token' => 'nullable|string',
            'interval_minutes' => 'sometimes|required|integer|min:1|max:1440',
            'is_enabled' => 'sometimes|boolean',
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
