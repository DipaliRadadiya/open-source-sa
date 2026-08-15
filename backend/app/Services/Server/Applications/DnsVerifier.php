<?php

namespace App\Services\Server\Applications;

use App\Models\ApplicationDomain;
use App\Services\Server\ServerOps;
use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;

/**
 * Does this name actually point at this server?
 *
 * Asked before a certificate is ever requested, and the answer is a gate rather
 * than a warning. Let's Encrypt allows **five authorisation failures per
 * hostname per hour**; a user whose DNS is wrong and who presses the button a
 * few times is locked out for an hour with nothing to show for it. One DNS
 * lookup we do ourselves costs nothing and turns that into a sentence.
 *
 * It also answers the question behind the most common SSL support ticket on
 * panels of this kind: the domain resolves fine, it just resolves to
 * Cloudflare. HTTP validation then hits Cloudflare rather than this server and
 * fails for a reason the error message never mentions. Naming it up front is
 * most of the value of this class.
 */
class DnsVerifier
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Resolve the name and record what was found.
     *
     * Always returns the row — a name that does not resolve is a fact to
     * display, not an error to throw.
     */
    public function verify(ApplicationDomain $domain): ApplicationDomain
    {
        // A panel-issued nip.io name encodes the address it resolves to, so it
        // is correct by construction and there is nothing to look up.
        if ($domain->is_test) {
            return tap($domain)->update([
                'dns_verified_at' => now(),
                'dns_resolved_ip' => $this->serverIp(),
                'behind_proxy' => false,
            ]);
        }

        $resolved = $this->resolve($domain->domain);
        $server = $this->serverIp();

        $domain->update([
            'dns_resolved_ip' => $resolved,
            'behind_proxy' => $resolved !== null && $this->isCloudflare($resolved),
            // Verified only when it points *here*. Pointing at Cloudflare is
            // resolvable but not verified, because HTTP-01 will not reach us.
            'dns_verified_at' => ($resolved !== null && $server !== null && $resolved === $server)
                ? now()
                : null,
        ]);

        return $domain->refresh();
    }

    /**
     * The first A record, or null when the name does not resolve.
     *
     * `dns_get_record` rather than shelling out to `dig`: it needs no
     * privileges and no package, and this runs in a request.
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
     * Cached: it is asked once per domain check and does not change between
     * them. The local route is used rather than an external lookup service so
     * that verification keeps working on a box with no outbound access.
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

            // `src` is the address on the outbound interface, which on a NAT'd
            // cloud instance — most of AWS, GCP, Azure, and anything with a
            // floating IP — is a private one, while the public address the
            // world reaches lives on a gateway this machine cannot see. Saying
            // "the server's IP is 10.0.0.5" there is not a smaller truth, it is
            // a wrong one, and callers compare it against DNS.
            //
            // Null means "cannot be determined from here", which is what a
            // caller has to handle anyway, rather than an answer that reads as
            // certain and is not.
            return IpRange::isPrivate($match[1]) ? null : $match[1];
        });
    }

    /**
     * Whether an address belongs to Cloudflare.
     *
     * The ranges live in config rather than being fetched: a network call in
     * the middle of a form submission is a worse failure mode than a list that
     * is a few months stale, and Cloudflare's ranges change rarely.
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
