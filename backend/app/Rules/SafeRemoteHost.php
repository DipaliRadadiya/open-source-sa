<?php

namespace App\Rules;

use App\Support\RemoteHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare hostname or IP the panel is allowed to open a connection to.
 *
 * The sibling of `SafeProviderHost`, which requires an `https` URL; an FTP or SFTP destination is
 * a bare `backup.example.com`, so that rule cannot be reused.
 *
 * Same refusals as the URL rule, same threat: an operator-supplied address the panel connects to
 * from inside the network.
 *
 * Private LAN ranges stay allowed (a NAS or backup box on the same network is the normal setup).
 * Loopback and the cloud metadata range are never a real backup destination and turn an outbound
 * connection into a local-privilege problem.
 */
class SafeRemoteHost implements ValidationRule
{
    public function __construct(
        private string $invalidKey = 'storage.test.invalid_host',
        private string $blockedKey = 'storage.test.forbidden_host',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail($this->invalidKey)->translate();

            return;
        }

        $host = strtolower(trim($value));

        // A scheme, path or credentials mean a URL was pasted into a host field. Refuse rather
        // than keep the part before the slash: it would store a different value than meant.
        if (str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '@')
            || str_contains($host, ' ')) {
            $fail($this->invalidKey)->translate();

            return;
        }

        // Bracketed IPv6 (`[::1]`) is accepted; the canonicaliser strips the brackets.
        $host = RemoteHost::canonical($host);

        // Refused before any range check: `127.1`, `2130706433`, `0x7f000001` and `0177.0.0.1`
        // all resolve to loopback while passing every dotted-quad test.
        if (RemoteHost::isUninterpretable($host) || ! $this->looksLikeHost($host)) {
            $fail($this->invalidKey)->translate();

            return;
        }

        // What it resolves to, as well as what was typed.
        if (RemoteHost::resolvesToBlocked($host)) {
            $fail($this->blockedKey)->translate();
        }
    }

    private function looksLikeHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Dot-separated labels of letters, digits and hyphens (none starting or ending with one).
        // A single label is allowed on purpose: `nas` resolves on a LAN with a search domain.
        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }
}
