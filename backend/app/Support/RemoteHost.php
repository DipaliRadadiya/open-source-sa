<?php

namespace App\Support;

use App\Exceptions\BlockedHostException;
use Closure;

/**
 * Whether a host the panel was asked to connect to is one it may connect to.
 *
 * Shared by `SafeProviderHost` (https endpoint URLs) and `SafeRemoteHost` (bare FTP/SFTP hosts) to
 * stop SSRF into loopback or the cloud metadata address.
 *
 * ## Why numeric spellings are refused
 *
 * `filter_var(FILTER_VALIDATE_IP)` accepts only the dotted quad, so `127.1`, `2130706433`,
 * `0x7f000001` and `0177.0.0.1` all failed the IP test, fell through to the hostname branch and
 * were accepted, yet `inet_aton` (resolver, libcurl, SSH and FTP clients) reaches 127.0.0.1 with
 * every one. `0251.0376.0251.0376` is octal for 169.254.169.254, the cloud metadata address.
 * Upgrading Guzzle does not fix this (CVE-2026-69246), and FTP/SFTP never used it.
 *
 * ## The rule
 *
 * A host is a canonical IP literal (the ranges decide) or a DNS name. A DNS name's last label
 * always contains a letter (RFC 1123 §2.1), so a final label without one is a numeric form in
 * disguise and is refused rather than resolved. This fails closed on spellings nobody has thought of.
 */
class RemoteHost
{
    /**
     * Normalise for inspection: lowercase, no IPv6 brackets, no trailing root dot (so suffix
     * checks see `example.com.` and `example.com` alike).
     */
    public static function canonical(string $host): string
    {
        return rtrim(strtolower(trim(trim($host), '[]')), '.');
    }

    /**
     * A host written in a way the panel refuses to interpret.
     *
     * Percent-encoding is refused outright, not decoded: `http://127.0.0.%31/` fails `filter_var`
     * as an IP, but libcurl decodes it to 127.0.0.1 and connects to loopback.
     */
    public static function isUninterpretable(string $host): bool
    {
        $host = self::canonical($host);

        if ($host === '') {
            return true;
        }

        if (str_contains($host, '%')) {
            return true;
        }

        // Whitespace is never part of a host; refused here so the helper is safe on its own.
        if (preg_match('/\s/', $host) === 1) {
            return true;
        }

        // A genuine IP literal is fine — `isBlocked()` judges it.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // A surviving colon is an IPv6 address that did not validate, usually because `parse_url`
        // ate part of it as a port (`https://fe80::1` gives `fe80:`). Reading it as a name would
        // let link-local through. A real IPv6 URL brackets its host.
        if (str_contains($host, ':')) {
            return true;
        }

        $labels = explode('.', $host);
        $last = end($labels);

        if ($last === '') {
            return true;
        }

        // A hex literal (`0x7f000001`, still 127.0.0.1) contains letters, so it must be caught
        // before the letter test.
        if (preg_match('/^0x[0-9a-f]+$/', $last) === 1) {
            return true;
        }

        // No letter in the final label: not a DNS name (`127.1`, `2130706433`, `0177.0.0.1`).
        return preg_match('/[a-z]/', $last) !== 1;
    }

    /** @var (Closure(string): array<int, string>)|null */
    private static ?Closure $resolver = null;

    /**
     * Swap name resolution, for tests. Null restores the system resolver.
     *
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The addresses a name resolves to; an IP literal is its own answer, an unresolvable name has
     * none.
     *
     * IPv4 via gethostbynamel(), which reads /etc/hosts as curl does; IPv6 from DNS.
     *
     * @return array<int, string>
     */
    public static function addresses(string $host): array
    {
        $host = self::canonical($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Blocked by what was typed, or by any address it resolves to.
     *
     * `isBlocked()` judges the text only, so `127.0.0.1.nip.io` (public wildcard DNS) passed.
     * Any one blocked address is enough: a name with two records is a coin toss between them.
     */
    public static function resolvesToBlocked(string $host): bool
    {
        if (self::isBlocked($host)) {
            return true;
        }

        foreach (self::addresses($host) as $address) {
            if (self::isBlocked($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * curl's `RESOLVE` entry for this URL: the host pinned to the address just checked, so a
     * second lookup cannot send the connection elsewhere (DNS rebinding).
     *
     * Null when there is nothing to pin: an IP literal, or a name that does not resolve.
     *
     * @throws BlockedHostException
     */
    public static function pin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = self::canonical($parts['host']);
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);

        if (self::resolvesToBlocked($host)) {
            throw new BlockedHostException($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $address = self::addresses($host)[0] ?? null;

        if ($address === null) {
            return null;
        }

        return "{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address);
    }

    /**
     * An address the panel must never connect to, whatever asked it to.
     *
     * Private LAN ranges are deliberately not blocked: a NAS or self-hosted git server on the
     * same network is a normal destination. Loopback and link-local are never a real remote
     * destination and turn an outbound connection into a local-privilege problem.
     */
    public static function isBlocked(string $host): bool
    {
        $host = self::canonical($host);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.')
                || str_starts_with($host, '169.254.') // cloud metadata
                || str_starts_with($host, '0.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // Compare the packed form so every spelling of `::1` is one answer.
            $packed = @inet_pton($host);

            if ($packed === false) {
                return true;
            }

            if ($packed === @inet_pton('::1')) {
                return true;
            }

            // fe80::/10 link-local (the IPv6 counterpart of 169.254.0.0/16), then ::ffff: mappings.
            if (str_starts_with($host, 'fe80:')) {
                return true;
            }

            $mapped = self::mappedIpv4($packed);

            return $mapped !== null && self::isBlocked($mapped);
        }

        return false;
    }

    /**
     * The IPv4 address inside an `::ffff:a.b.c.d` mapping, if this is one.
     *
     * `::ffff:127.0.0.1` is loopback written as IPv6; a `127.` string check would miss it.
     */
    private static function mappedIpv4(string $packed): ?string
    {
        if (strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 12) !== "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return null;
        }

        return implode('.', array_map('ord', str_split(substr($packed, 12, 4))));
    }
}
