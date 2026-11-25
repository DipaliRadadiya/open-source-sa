<?php

namespace App\Services\Server;

use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The address the internet reaches this server on.
 *
 * `hostname -I` is wrong on anything NAT'd (AWS, GCP, Azure, floating IPs): it gives the
 * **private** address, which is not the one to point DNS at.
 *
 * Two sources, in order of trust:
 *
 *  1. **The local route table.** What `DnsVerifier::serverIp()` reads; needs no network. Right
 *     when it answers a public address, useless when it answers a private one (the NAT case).
 *  2. **The cloud metadata service.** Link-local (169.254.169.254), so no third party is
 *     contacted. Never use an external "what is my IP" service for a self-hosted panel.
 *
 * Null when neither answers (e.g. behind a hardware NAT it cannot introspect); the caller must
 * render that as "could not determine", not a blank.
 *
 * Every probe is bounded and its failure swallowed: this runs on a dashboard request, and a
 * missing metadata service must cost a timeout, not an error.
 */
class ServerPublicIp
{
    /**
     * Providers, each a request plus the way to read an address out of it.
     *
     * Kept as data rather than a class per cloud: they differ only in a URL, a header and where
     * the string sits.
     *
     * @return array<int, array{url: string, headers: array<string, string>, json: ?string}>
     */
    private function sources(): array
    {
        $base = rtrim((string) config('server.metadata_base', 'http://169.254.169.254'), '/');

        return [
            // AWS: the unauthenticated read below only works where IMDSv1 is permitted (IMDSv2
            // needs a token first).
            ['url' => "{$base}/latest/meta-data/public-ipv4", 'headers' => [], 'json' => null],
            ['url' => "{$base}/metadata/v1/interfaces/public/0/ipv4/address", 'headers' => [], 'json' => null],
            ['url' => "{$base}/hetzner/v1/metadata/public-ipv4", 'headers' => [], 'json' => null],
            [
                'url' => "{$base}/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip",
                'headers' => ['Metadata-Flavor' => 'Google'],
                'json' => null,
            ],
            [
                'url' => "{$base}/metadata/instance/network/interface/0/ipv4/ipAddress/0/publicIpAddress?api-version=2021-02-01",
                'headers' => ['Metadata' => 'true'],
                'json' => null,
            ],
        ];
    }

    /**
     * @param  callable(): ?string  $fromRoute  the route-table answer, injected
     *                                          so this class does not depend on
     *                                          the DNS verifier that owns it
     */
    public function detect(callable $fromRoute): ?string
    {
        return Cache::remember('server.public_ip.resolved', now()->addHour(), function () use ($fromRoute): ?string {
            $routed = $fromRoute();

            // Already public: nothing on the network can improve on it.
            if ($routed !== null) {
                return $routed;
            }

            return $this->fromMetadata();
        });
    }

    private function fromMetadata(): ?string
    {
        $timeout = max(1, (int) config('server.metadata_timeout', 2));

        foreach ($this->sources() as $source) {
            try {
                $response = Http::withHeaders($source['headers'])
                    ->timeout($timeout)
                    ->connectTimeout($timeout)
                    ->get($source['url']);
            } catch (Throwable) {
                // No metadata service here (expected on bare metal); try the next.
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $candidate = trim($response->body());

            // An HTML error page or a private address is not the public IP.
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (IpRange::isPrivate($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
