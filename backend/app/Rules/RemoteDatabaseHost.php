<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The address a remote database user may connect from: one IPv4 address or
 * an IPv4 range.
 *
 * Bug #37: a shape-only regex took 999.1.1.1. The database accepted the user,
 * ufw refused the rule with a 500, and the half-made rule could not be
 * deleted. Like v7, an address that is not remote at all is refused too —
 * 0.0.0.0, a /0 and loopback have their own choices ("Anywhere", "Local").
 *
 * Only when the access is (or may be) remote. With "anywhere" or "localhost"
 * the host is not used, and the API reference has always shown
 * `{"connection_preference": "anywhere", "host": "0.0.0.0/0"}` — so for those
 * the old shape check stays, and a caller following the docs keeps working.
 */
class RemoteDatabaseHost implements ValidationRule
{
    public function __construct(private mixed $preference = 'remote') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array($this->preference, ['localhost', 'anywhere'], true)) {
            if (! is_string($value) || preg_match('/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/', $value) !== 1) {
                $fail('errors/database.remote_host_invalid')->translate();
            }

            return;
        }

        $parts = is_string($value) ? explode('/', $value) : [];
        $ip = $parts[0] ?? '';
        $prefix = $parts[1] ?? null;

        if (count($parts) > 2
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > 32))) {
            $fail('errors/database.remote_host_invalid')->translate();

            return;
        }

        if ($ip === '0.0.0.0' || $prefix === '0' || str_starts_with($ip, '127.')) {
            $fail('errors/database.remote_host_not_remote')->translate();
        }
    }
}
