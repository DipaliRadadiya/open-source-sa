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
     * Why a port the user chose cannot be used, or null when it can.
     *
     * Deliberately a **weaker** test than allocation. Skipping every name in
     * /etc/services is right when the panel is choosing for itself, and wrong
     * when the owner of the server is choosing: 8080 is `http-alt` in that
     * file and is also the single most common port anyone runs a Node app on.
     * Refusing it would be the panel overruling someone about their own
     * machine.
     *
     * So only real conflicts are refused — another application the panel
     * manages, or something listening right now. A registered name is not a
     * conflict; it is a coincidence of naming.
     *
     * @return 'in_use_by_app'|'in_use'|null
     */
    public function conflict(int $port, ?Application $except = null): ?string
    {
        $taken = Application::query()
            ->whereNotNull('app_port')
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->pluck('app_port')
            ->all();

        if (in_array($port, $taken, true)) {
            return 'in_use_by_app';
        }

        return in_array($port, $this->listening(), true) ? 'in_use' : null;
    }

    /**
     * Everything the screen needs to say about a port before it is submitted.
     *
     * Three outcomes, not two. A conflict is a refusal; a port that merely has
     * a name in /etc/services is a **warning** — the user may well mean it,
     * since 8080 is `http-alt` there and is also where most Node applications
     * listen. Telling them which service owns the name lets them decide;
     * silently allowing it teaches them nothing, and refusing it would be
     * wrong.
     *
     * @return array{port: int, available: bool, reason: ?string, service: ?string, suggested_port: ?int}
     */
    public function inspect(int $port, ?Application $except = null): array
    {
        $conflict = $this->conflict($port, $except);
        $service = $this->serviceName($port);

        return [
            'port' => $port,
            'available' => $conflict === null,
            // `in_use_by_app` and `in_use` block; `registered` does not.
            'reason' => $conflict ?? ($service !== null ? 'registered' : null),
            'service' => $service,
            // Only when the answer is no — offering an alternative to someone
            // whose choice was fine would just be noise.
            'suggested_port' => $conflict === null ? null : $this->suggest(),
        ];
    }

    /**
     * A free port to offer, or null when the range is exhausted — a suggestion
     * is a convenience, so failing to find one must not fail the check.
     */
    private function suggest(): ?int
    {
        try {
            return $this->allocate();
        } catch (NoPortAvailableException) {
            return null;
        }
    }

    /**
     * What `/etc/services` calls a port, if anything.
     */
    private function serviceName(int $port): ?string
    {
        $contents = @file_get_contents('/etc/services');

        if ($contents === false) {
            return null;
        }

        return preg_match('/^([a-z0-9._-]+)\s+'.$port.'\/tcp/mi', $contents, $matches) === 1
            ? $matches[1]
            : null;
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
