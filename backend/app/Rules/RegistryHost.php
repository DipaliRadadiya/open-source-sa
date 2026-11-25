<?php

namespace App\Rules;

use App\Models\Registry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A registry address the panel can turn into a `config.json` key.
 *
 * A key Docker does not recognise is **silently ignored**, and the pull then fails with the same
 * message as when no credential is stored. So an address the panel cannot interpret is refused
 * at the form, or the registry looks configured and never authenticates.
 *
 * A scheme and a trailing slash are tolerated (people paste them; the model strips both).
 * Refused: a path (`ghcr.io/myorg` most of all), a space, embedded credentials, a non-host.
 */
class RegistryHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);

        // Docker Hub's legacy index URL has a path, but is what Docker itself writes: allow it.
        if ($value === Registry::HUB_KEY) {
            return;
        }

        $bare = (string) preg_replace('#^https?://#i', '', $value);
        $bare = rtrim($bare, '/');

        if ($bare === '') {
            $fail(__('validation.custom.registry.empty'));

            return;
        }

        // A path means a namespace or repository, not a registry (the common mistake, so it
        // gets its own message).
        if (str_contains($bare, '/')) {
            $fail(__('validation.custom.registry.path'));

            return;
        }

        // Userinfo would put a credential in a column that is not encrypted.
        if (str_contains($bare, '@')) {
            $fail(__('validation.custom.registry.credentials'));

            return;
        }

        // host[:port]. Not `filter_var(FILTER_VALIDATE_DOMAIN)`: it rejects underscores, which
        // Docker tolerates in a local hostname.
        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:[0-9]{1,5})?$/', $bare) !== 1) {
            $fail(__('validation.custom.registry.host'));

            return;
        }

        $port = str_contains($bare, ':') ? (int) explode(':', $bare)[1] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            $fail(__('validation.custom.registry.port'));
        }
    }
}
