<?php

namespace App\Rules;

use App\Support\RemoteHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare hostname or IP the panel is allowed to open a connection to.
 *
 * The sibling of `SafeProviderHost`, which cannot be reused here: that rule
 * runs the value through `parse_url` and requires a **scheme**, then insists
 * the scheme is `https`. An FTP or SFTP destination is addressed as
 * `backup.example.com` — no scheme, no URL — so every valid host would fail
 * it, and the obvious "fix" of prepending `https://` would validate a string
 * nobody is going to connect to.
 *
 * The refusals are deliberately the same as the URL rule's, because the threat
 * is the same: an operator-supplied address that the panel then connects to
 * from inside the network.
 *
 * Private LAN ranges stay allowed, for the same reason `SafeProviderHost`
 * allows them — a NAS or a backup box on the same network is the normal
 * deployment for this feature, and blocking it would break a legitimate setup
 * to defend against someone who already holds the `storage` manage permission.
 * Loopback and the cloud metadata range are a different matter: neither is
 * ever a real backup destination, and both turn an outbound connection into a
 * local-privilege problem.
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

        // A scheme, a path, credentials or a port glued on all mean the user
        // pasted a URL into a host field. Refuse rather than silently keeping
        // the part before the slash: the value they meant and the value we
        // would store are different, and the difference shows up as a failed
        // backup much later.
        if (str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '@')
            || str_contains($host, ' ')) {
            $fail($this->invalidKey)->translate();

            return;
        }

        // Bracketed IPv6 (`[::1]`) is accepted as a way of writing an
        // address; the shared canonicaliser strips the brackets.
        $host = RemoteHost::canonical($host);

        // Refused before any range check: a host the panel cannot interpret
        // the same way the transport will is one it cannot make a decision
        // about. `127.1`, `2130706433`, `0x7f000001` and `0177.0.0.1` all
        // resolve to loopback while passing every dotted-quad test.
        if (RemoteHost::isUninterpretable($host) || ! $this->looksLikeHost($host)) {
            $fail($this->invalidKey)->translate();

            return;
        }

        if (RemoteHost::isBlocked($host)) {
            $fail($this->blockedKey)->translate();
        }
    }

    private function looksLikeHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // A hostname: labels of letters, digits and hyphens, separated by
        // dots, no label starting or ending with a hyphen. Deliberately
        // permits a single label — `nas` is a resolvable name on a LAN with
        // a search domain, and this feature's users have those.
        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }
}
