<?php

namespace App\Rules;

use App\Services\Server\SshKeyManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A public key the server will really log in with.
 *
 * Well-formed is not enough: a DSA key, or RSA under 2048 bits, was accepted,
 * written to authorized_keys and listed — and then refused by sshd at login,
 * with nothing in the panel saying why (2026-10-28). Each gets its own
 * message, because "not a key" and "a key we refuse" are different mistakes.
 */
class SshPublicKey implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $keys = app(SshKeyManager::class);

        if (! is_string($value) || ! $keys->isValidPublicKey($value)) {
            $fail('errors/system-user.invalid_public_key')->translate();

            return;
        }

        match ($keys->rejection($value)) {
            'dsa' => $fail('errors/system-user.dsa_public_key')->translate(),
            'weak_rsa' => $fail('errors/system-user.weak_rsa_public_key')->translate(['bits' => SshKeyManager::MIN_RSA_BITS]),
            default => null,
        };
    }
}
