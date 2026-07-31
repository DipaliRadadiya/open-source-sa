<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\NoPortAvailableException;
use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * Picks the localhost port an application's process listens on.
 *
 * Two sources of truth, and both matter. The database knows what the panel has
 * handed out; the kernel knows what is actually bound — including everything
 * on the box the panel did not create. Consulting only our own records would
 * hand a site a port that a migrated app, a system daemon or a hand-started
 * process is already using, and the failure is nasty: the app cannot bind, but
 * the reverse proxy happily forwards that site's traffic to whatever *is*
 * listening there.
 */
class PortAllocator
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The lowest free port in the configured range.
     *
     * @throws NoPortAvailableException
     */
    public function allocate(): int
    {
        $from = (int) config('server.applications.port_range.from', 3000);
        $to = (int) config('server.applications.port_range.to', 3999);

        $taken = Application::query()->whereNotNull('app_port')->pluck('app_port')->all();
        $unavailable = [...$taken, ...$this->listening(), ...$this->registered()];

        for ($port = $from; $port <= $to; $port++) {
            if (! in_array($port, $unavailable, true)) {
                return $port;
            }
        }

        throw new NoPortAvailableException($from, $to);
    }

    /**
     * Whether a port the user chose themselves can be used.
     */
    public function available(int $port, ?Application $except = null): bool
    {
        $taken = Application::query()
            ->whereNotNull('app_port')
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->pluck('app_port')
            ->all();

        return ! in_array($port, $taken, true)
            && ! in_array($port, $this->listening(), true)
            && ! in_array($port, $this->registered(), true);
    }

    /**
     * Ports `/etc/services` says belong to something, whether or not it is
     * installed yet.
     *
     * Listening-only was not enough, and the gap is not theoretical: **3306 is
     * MySQL** and sits inside the default range. On a box where MySQL has not
     * been installed yet nothing is listening there, so an application would
     * be given 3306 — and installing MySQL afterwards breaks one of the two,
     * long after anyone would connect the two events.
     *
     * `/etc/services` rather than a list of our own: it is the machine's own
     * record of what a port means, it covers services we have never heard of,
     * and it is maintained by the distribution rather than by us.
     *
     * @return array<int, int>
     */
    private function registered(): array
    {
        $extra = array_map('intval', (array) config('server.applications.reserved_ports', []));

        $contents = @file_get_contents('/etc/services');

        if ($contents === false) {
            return $extra;
        }

        // "mysql   3306/tcp   # comment" — name, then port/protocol.
        preg_match_all('/^[a-z0-9._-]+\s+(\d+)\/tcp/mi', $contents, $matches);

        return [...$extra, ...array_map('intval', $matches[1] ?? [])];
    }

    /**
     * Ports with a listening TCP socket right now.
     *
     * A failed read returns nothing rather than throwing: the unique index and
     * the app's own bind will still catch a collision, and refusing to create
     * an application because `ss` is missing would be the wrong trade.
     *
     * @return array<int, int>
     */
    private function listening(): array
    {
        $result = $this->serverOps->run(
            ['ss', '-ltnH'],
            ['feature' => 'application', 'op' => 'port_scan'],
        );

        if ($result->failed()) {
            return [];
        }

        // "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*" — the port is the tail of the
        // fourth column, after an IPv4 address, an IPv6 address or a `*`.
        preg_match_all('/\s\S*:(\d+)\s/', $result->output(), $matches);

        return array_map('intval', array_unique($matches[1] ?? []));
    }
}
