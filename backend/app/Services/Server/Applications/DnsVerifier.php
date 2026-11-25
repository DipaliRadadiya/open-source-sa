<?php

namespace App\Services\Server\Applications;

use App\Models\ApplicationDomain;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerPublicIp;
use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;

/**
 * Does this name actually point at this server?
 *
 * Asked before a certificate is requested, as a gate rather than a warning: Let's Encrypt
 * allows five authorisation failures per hostname per hour. Also detects a name that resolves
 * to Cloudflare, where HTTP validation would hit Cloudflare instead of this server.
 */
class DnsVerifier
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Resolve the name and record what was found.
     *
     * Always returns the row: a name that does not resolve is a fact, not an error.
     */
    public function verify(ApplicationDomain $domain): ApplicationDomain
    {
        // A panel-issued nip.io name encodes its own address, so there is nothing to look up. Read
        // the address from the name, not `serverIp()`, which is null behind NAT.
        if ($domain->is_test) {
            return tap($domain)->update([
                'dns_verified_at' => now(),
                'dns_resolved_ip' => self::encodedIp($domain->domain)
                    ?? app(ServerPublicIp::class)->detect(fn (): ?string => $this->serverIp()),
                'behind_proxy' => false,
            ]);
        }

        $resolved = $this->resolve($domain->domain);
        $server = $this->serverIp();

        $domain->update([
            'dns_resolved_ip' => $resolved,
            'behind_proxy' => $resolved !== null && $this->isCloudflare($resolved),
            // Verified only when it points here; Cloudflare is resolvable but HTTP-01 won't reach us.
            'dns_verified_at' => ($resolved !== null && $server !== null && $resolved === $server)
                ? now()
                : null,
        ]);

        return $domain->refresh();
    }

    /**
     * The IPv4 address a wildcard-DNS name answers with — `shop.1-2-3-4.nip.io`,
     * `shop.1.2.3.4.nip.io` or `shop-1-2-3-4.nip.io` — or null when the name
     * carries none, or is not under one of those services.
     */
    public static function encodedIp(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        foreach ((array) config('server.temporary_domain_suffixes', ['nip.io', 'sslip.io']) as $suffix) {
            $suffix = '.'.strtolower((string) $suffix);

            if (! str_ends_with($domain, $suffix)) {
                continue;
            }

            $name = substr($domain, 0, -strlen($suffix));

            if (preg_match('/(?:^|[.-])(\d{1,3})[.-](\d{1,3})[.-](\d{1,3})[.-](\d{1,3})$/', $name, $m) !== 1) {
                return null;
            }

            $ip = "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $ip;
        }

        return null;
    }

    /**
     * The first A record, or null when the name does not resolve.
     *
     * `dns_get_record` rather than `dig`: no privileges or package needed, and it runs in a request.
     */
    public function resolve(string $domain): ?string
    {
        $records = @dns_get_record($domain, DNS_A);

        if (! is_array($records) || $records === []) {
            return null;
        }

        return $records[0]['ip'] ?? null;
    }

    /**
     * This server's own public address.
     *
     * Cached for an hour. Uses the local route, not an external lookup service, so it works with
     * no outbound access.
     */
    public function serverIp(): ?string
    {
        return Cache::remember('server.public_ip', now()->addHour(), function (): ?string {
            $result = $this->serverOps->run(
                ['ip', '-4', 'route', 'get', '1.1.1.1'],
                ['feature' => 'application', 'op' => 'detect_ip'],
            );

            if ($result->failed()) {
                return null;
            }

            if (preg_match('/\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})/', $result->output(), $match) !== 1) {
                return null;
            }

            // `src` on a NAT'd cloud instance is a private address, not the public one. Return
            // null ("cannot tell from here") rather than a wrong IP that callers compare to DNS.
            return IpRange::isPrivate($match[1]) ? null : $match[1];
        });
    }

    /**
     * Whether an address belongs to Cloudflare.
     *
     * Ranges live in config, not fetched: a network call during a form submission is worse than a
     * slightly stale list.
     */
    public function isCloudflare(string $ip): bool
    {
        foreach ((array) config('server.cloudflare_ranges', []) as $range) {
            if ($this->inRange($ip, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
