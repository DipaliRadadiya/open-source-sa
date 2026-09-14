<?php

namespace App\Support;

/**
 * Whether a host the panel was asked to connect to is one it may connect to.
 *
 * One definition, shared by `SafeProviderHost` (https endpoint URLs — storage
 * and self-hosted git) and `SafeRemoteHost` (bare FTP/SFTP hosts). They had
 * their own copies of the loopback and metadata checks, and both copies had
 * the same hole.
 *
 * ## The hole, found 2026-09-14
 *
 * Both rules asked `filter_var($host, FILTER_VALIDATE_IP)` and applied the
 * range checks *only if that succeeded*. `filter_var` accepts exactly one
 * spelling of an address — the dotted quad — so every other spelling failed
 * the IP test, fell through to the hostname branch, and was accepted:
 *
 *   127.1 · 2130706433 · 0x7f000001 · 0177.0.0.1
 *
 * all reach 127.0.0.1, because `inet_aton` (which the resolver, libcurl and
 * the SSH and FTP clients all use) accepts short, decimal, hex and octal
 * forms. And `0251.0376.0251.0376` is octal for **169.254.169.254** — the
 * cloud metadata address, the single target these rules exist to deny.
 *
 * Prompted by CVE-2026-69246 (Guzzle, "noncanonical host can bypass
 * host-based checks"). That advisory is about Guzzle handing libcurl a URI it
 * re-parses differently — patched in 7.15.2 — but it also states that the
 * noncanonical *numeric* spellings "remain accepted after the patch and reach
 * whatever the transport reads them as". Upgrading does not fix this; only
 * refusing the spellings does. And FTP/SFTP never went through Guzzle at all,
 * so for those the panel's own check was the only thing standing there.
 *
 * ## The rule
 *
 * A host is either a canonical IP literal — in which case the ranges decide —
 * or a DNS name. **A DNS name's last label always contains a letter.** No
 * registry has ever delegated an all-numeric TLD, and it is forbidden by
 * RFC 1123 §2.1 precisely so that a name can never be confused with an
 * address. So anything whose final label has no letter is a numeric form
 * wearing a hostname's clothes, and is refused rather than resolved — this
 * fails *closed* on spellings nobody has thought of yet, which the enumerate-
 * the-bad-forms approach cannot.
 */
class RemoteHost
{
    /**
     * Normalise for inspection: lowercase, no surrounding IPv6 brackets, no
     * trailing root dot (`example.com.` and `example.com` are the same name,
     * and only one of them would match a suffix check).
     */
    public static function canonical(string $host): string
    {
        return rtrim(strtolower(trim(trim($host), '[]')), '.');
    }

    /**
     * A host written in a way the panel refuses to interpret.
     *
     * Percent-encoding is in here because of the advisory's own example:
     * `http://127.0.0.%31/` is rejected by `filter_var` as an IP literal, and
     * libcurl decodes it to 127.0.0.1 and connects to loopback. A host field
     * has no legitimate use for percent-encoding, so it is refused outright
     * rather than decoded and re-checked — decoding invites a second round of
     * the same divergence.
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

        // Whitespace is never part of a host. Refused here rather than left to
        // each caller's own field regex, so the helper is safe to use on its
        // own.
        if (preg_match('/\s/', $host) === 1) {
            return true;
        }

        // A genuine IP literal is fine — `isBlocked()` judges it.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // A colon that survived to here means an IPv6 address that did not
        // validate — usually because `parse_url` already ate part of it as a
        // port. `https://fe80::1` yields the host `fe80:`, which is neither a
        // valid address nor a name, and reading it as a name would let
        // link-local through. A real IPv6 URL brackets its host.
        if (str_contains($host, ':')) {
            return true;
        }

        $labels = explode('.', $host);
        $last = end($labels);

        if ($last === '') {
            return true;
        }

        // A hex literal is the exception that "must contain a letter" does not
        // catch — `0x7f000001` contains an x and an f and is still 127.0.0.1.
        // Caught before the letter test rather than after, because the letter
        // test says yes to it.
        if (preg_match('/^0x[0-9a-f]+$/', $last) === 1) {
            return true;
        }

        // No letter in the final label means this is not a DNS name.
        // `127.1`, `2130706433` and `0177.0.0.1` all land here.
        return preg_match('/[a-z]/', $last) !== 1;
    }

    /**
     * An address the panel must never connect to, whatever asked it to.
     *
     * Private LAN ranges are deliberately NOT blocked: a NAS or a self-hosted
     * git server on the same network is a normal, intended destination for
     * this panel, and refusing them would break a legitimate setup to defend
     * against someone who already holds the permission to configure it.
     * Loopback and link-local are different — neither is ever a real remote
     * destination, and both turn an outbound connection into a local-privilege
     * problem.
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
            // Compared against the expanded form so `::1`, `0:0:…:1` and
            // `0000:…:0001` are one answer rather than three string cases.
            $packed = @inet_pton($host);

            if ($packed === false) {
                return true;
            }

            if ($packed === @inet_pton('::1')) {
                return true;
            }

            // fe80::/10 link-local, which is where 169.254.0.0/16 lives in
            // IPv6 — and the ::ffff: forms that map an IPv4 address into v6.
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
     * Checked because `::ffff:127.0.0.1` is loopback written as IPv6, and a
     * string comparison against `127.` would never see it.
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
