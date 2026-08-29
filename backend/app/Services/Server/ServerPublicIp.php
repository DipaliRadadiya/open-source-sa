<?php

namespace App\Services\Server;

use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The address the internet reaches this server on.
 *
 * The dashboard used to show `hostname -I`'s first address and call it the
 * server's IP. On a machine with a directly attached public address — most
 * small VPS providers — that is right. On anything NAT'd (AWS, GCP, Azure, or
 * any provider with a floating IP) it is the **private** address, and the
 * dashboard was confidently showing `10.0.0.5` as the address to point DNS at.
 *
 * Two sources, in order of how much they can be trusted:
 *
 *  1. **The local route table.** What `DnsVerifier::serverIp()` already reads,
 *     and the only source that needs no network at all. It is authoritative
 *     when it answers a public address and useless when it answers a private
 *     one, which is exactly the NAT case.
 *  2. **The cloud metadata service.** Link-local (169.254.169.254), so the
 *     request never leaves the host's own network segment and no third party
 *     is contacted — unlike an external "what is my IP" service, which is the
 *     obvious alternative and the wrong one for a self-hosted panel.
 *
 * Null when neither answers. That is a real state — a machine behind a
 * hardware NAT it cannot introspect genuinely cannot know its own public
 * address — and the caller has to render it as "could not determine" rather
 * than as a blank that reads like a bug.
 *
 * Every probe is bounded and its failure is swallowed: this runs on a
 * dashboard request, and a metadata service that does not exist must cost a
 * timeout, not an error.
 */
class ServerPublicIp
{
    /**
     * Providers, each a request plus the way to read an address out of it.
     *
     * Kept as data rather than a class per cloud: they differ only in a URL,
     * a header and where the string sits, and five tiny drivers would be more
     * code than the thing they abstract.
     *
     * @return array<int, array{url: string, headers: array<string, string>, json: ?string}>
     */
    private function sources(): array
    {
        $base = rtrim((string) config('server.metadata_base', 'http://169.254.169.254'), '/');

        return [
            // AWS IMDSv2 requires a token first; the unauthenticated read
            // below still works on instances where IMDSv1 is permitted, which
            // is why both are here rather than only the newer one.
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

            // Already public: nothing on the network can improve on what the
            // machine can see about itself.
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
                // No metadata service here, or nothing listening on the
                // link-local address. Expected on bare metal; try the next.
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $candidate = trim($response->body());

            // A metadata service that answers with an HTML error page, or with
            // a private address, has not told us the public IP — and returning
            // it would be the same wrong answer in a new place.
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
