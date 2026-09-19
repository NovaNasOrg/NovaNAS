<?php

namespace App\Http\Requests;

use App\Rules\ProxyDomainRule;
use App\Services\SslService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation request for reverse proxy host updates.
 */
class UpdateProxyHostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => is_string($this->input('domain')) ? strtolower(trim($this->input('domain'))) : $this->input('domain'),
            'target_host' => is_string($this->input('target_host')) ? trim($this->input('target_host')) : $this->input('target_host'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', new ProxyDomainRule(app(SslService::class)), 'unique:proxy_hosts,domain,'.$this->route('host')?->id],
            'target_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/'],
            'target_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'target_protocol' => ['required', 'string', 'in:http,https'],
            'websocket_enabled' => ['boolean'],
            'https_redirect' => ['boolean'],
            'enabled' => ['boolean'],
            'auth_enabled' => ['boolean'],
            'auth_user_ids' => ['array'],
            'auth_user_ids.*' => ['integer', 'exists:users,id'],
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
            'domain.required' => 'Please enter a domain name.',
            'domain.max' => 'The domain may not be greater than 253 characters.',
            'domain.unique' => 'A proxy host with this domain already exists.',
            'target_host.required' => 'Please enter the forward host.',
            'target_host.regex' => 'The forward host must be a hostname or IP address without scheme, port, or path.',
            'target_port.required' => 'Please enter the forward port.',
            'target_port.integer' => 'The forward port must be a number.',
            'target_port.min' => 'The forward port must be between 1 and 65535.',
            'target_port.max' => 'The forward port must be between 1 and 65535.',
            'target_protocol.required' => 'Please select the backend scheme.',
            'target_protocol.in' => 'The backend scheme must be http or https.',
        ];
    }
}
