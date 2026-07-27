<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes for a valid IPv4/IPv6 address or CIDR range (e.g. 1.2.3.4,
 * 1.2.3.0/24, 2001:db8::/32).
 */
class IpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('errors/firewall.invalid_source')->translate();

            return;
        }

        $parts = explode('/', $value);

        if (count($parts) > 2) {
            $fail('errors/firewall.invalid_source')->translate();

            return;
        }

        $isV4 = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isV4 && ! $isV6) {
            $fail('errors/firewall.invalid_source')->translate();

            return;
        }

        if (count($parts) === 2) {
            $max = $isV4 ? 32 : 128;

            if (! ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                $fail('errors/firewall.invalid_source')->translate();
            }
        }
    }
}
