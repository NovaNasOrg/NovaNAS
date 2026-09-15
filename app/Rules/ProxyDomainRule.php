<?php

namespace App\Rules;

use App\Models\DynDnsConfig;
use App\Services\SslService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a domain name for a reverse proxy host.
 *
 * The domain must be a valid FQDN (which also rejects raw IP addresses and
 * bare "localhost"), and the NAS hostname as well as every DynDNS-managed
 * full domain are forbidden since they must keep pointing at the NAS itself.
 */
class ProxyDomainRule implements ValidationRule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private SslService $sslService) {}

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid domain name (e.g. app.example.com).');

            return;
        }

        $domain = strtolower(trim($value));

        if (! $this->isValidFqdn($domain)) {
            $fail('The :attribute must be a valid domain name (e.g. app.example.com).');

            return;
        }

        $hostname = $this->sslService->getCurrentHostname();

        if ($hostname !== null && $domain === strtolower($hostname)) {
            $fail('The NAS hostname cannot be reverse proxied.');

            return;
        }

        $dyndnsDomains = DynDnsConfig::query()
            ->get()
            ->map(fn (DynDnsConfig $config) => strtolower($config->full_domain));

        if ($dyndnsDomains->contains($domain)) {
            $fail('This domain is managed by DynDNS and points to this NAS.');

            return;
        }
    }

    /**
     * Check that the value is a syntactically valid FQDN.
     *
     * Requires at least one dot and an alphabetic TLD, which also rejects
     * raw IP addresses and "localhost".
     */
    private function isValidFqdn(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if (! str_contains($domain, '.')) {
            return false;
        }

        $labels = explode('.', $domain);

        $tld = array_pop($labels);

        // TLD must be purely alphabetic (rejects IP last octets and punycode fragments)
        if ($tld === '' || strlen($tld) < 2 || ! ctype_alpha($tld)) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
