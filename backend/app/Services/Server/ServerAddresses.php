<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * Every address a request from this server to itself can arrive from.
 *
 * Not the public IP: on a NAT'd cloud instance the public address lives on a
 * gateway, and a request this machine makes to its own domain reaches the
 * web server from a private address on its own interface. So the answer is
 * what the interfaces carry (`scope global`, link-local excluded) plus
 * loopback, which is also what an application that resolves its own name
 * through /etc/hosts uses.
 *
 * Cached for an hour like the other address lookups: vhosts are rendered
 * often, and the interfaces of a server rarely change.
 */
class ServerAddresses
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array<int, string>
     */
    public function local(): array
    {
        return Cache::remember('server.local_addresses', now()->addHour(), function (): array {
            $result = $this->serverOps->run(
                ['ip', '-o', 'addr', 'show', 'scope', 'global'],
                ['feature' => 'server', 'op' => 'local_addresses'],
            );

            $found = [];

            if ($result->ok) {
                preg_match_all('/\binet6?\s+([0-9a-fA-F.:]+)\//', $result->output(), $matches);

                $found = array_filter($matches[1], fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);
            }

            return array_values(array_unique(['127.0.0.1', '::1', ...$found]));
        });
    }
}
