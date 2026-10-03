<?php

namespace App\Rules;

use App\Models\Registry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A registry address the panel can turn into a `config.json` key.
 *
 * Validation rather than best-effort acceptance, and the reason is specific: a
 * key Docker does not recognise is **silently ignored**, and the pull then fails
 * with the identical message it gives when no credential is stored at all
 * (measured against a real private Hub repository). So an address the panel
 * cannot interpret must be refused at the form. Accepting it produces a registry
 * that looks configured, tests green against Hub's default, and never
 * authenticates anything — the hardest possible failure to diagnose.
 *
 * A scheme and a trailing slash are tolerated because people paste them, and the
 * model strips both. What is refused is anything with a path, a space, credentials
 * embedded in it, or a host that is not a host — `ghcr.io/myorg` most of all,
 * since it looks exactly like the thing being configured and is not.
 */
class RegistryHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);

        // Docker Hub's legacy index URL is a URL with a path, and it is the one
        // literal that must be allowed through: it is what Docker itself writes.
        if ($value === Registry::HUB_KEY) {
            return;
        }

        $bare = (string) preg_replace('#^https?://#i', '', $value);
        $bare = rtrim($bare, '/');

        if ($bare === '') {
            $fail(__('validation.custom.registry.empty'));

            return;
        }

        // A path means the user gave a namespace or a repository, not a registry.
        // Named separately from the character check below so the message can say
        // which mistake was made — this is the common one.
        if (str_contains($bare, '/')) {
            $fail(__('validation.custom.registry.path'));

            return;
        }

        // A userinfo section would put a credential in a column that is not
        // encrypted, next to one that is.
        if (str_contains($bare, '@')) {
            $fail(__('validation.custom.registry.credentials'));

            return;
        }

        // host[:port]. Deliberately not `filter_var(FILTER_VALIDATE_DOMAIN)`,
        // which accepts a single label and rejects an underscore that Docker
        // itself tolerates in a local hostname.
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
