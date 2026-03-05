<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a subdomain follows DNS naming rules compatible with PowerDNS.
 *
 * Rules according to RFC 1123:
 * - Must be 1-63 characters
 * - Must contain only lowercase letters (a-z), numbers (0-9), and hyphens (-)
 * - Cannot start or end with a hyphen
 * - Cannot have consecutive hyphens in positions 3 and 4 (RFC 5891)
 * - Cannot be entirely numeric
 */
class SubdomainRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute must be a string.');
            return;
        }

        if (strlen($value) > 63) {
            $fail('The :attribute may not be greater than 63 characters.');
            return;
        }

        // Only lowercase letters, numbers, hyphens; no leading/trailing hyphens; min 1 char
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $value)) {
            $fail('The :attribute may only contain lowercase letters, numbers, and hyphens, and must not start or end with a hyphen.');
            return;
        }

        // Block consecutive hyphens in positions 3 and 4 (RFC 5891 — reserves xn-- and similar ACE prefixes)
        if (strlen($value) >= 4 && substr($value, 2, 2) === '--') {
            $fail('The :attribute must not have hyphens in both the 3rd and 4th position.');
            return;
        }

        // Block all-numeric labels to avoid confusion with IP address octets
        if (ctype_digit($value)) {
            $fail('The :attribute must not be entirely numeric.');
            return;
        }
    }
}
