<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\NoPortAvailableException;
use App\Models\Application;
use App\Models\DockerDatabase;
use App\Services\Server\ServerOps;

/**
 * Picks the localhost port an application's process listens on.
 *
 * Two sources of truth: the database knows what the panel handed out, the kernel knows what is
 * bound, including things the panel did not create. Consulting only our records could hand a
 * site a port already in use: the app cannot bind, but the reverse proxy forwards its traffic
 * to whatever *is* listening there.
 */
class PortAllocator
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The lowest free port in the configured range.
     *
     * `$also` is ports this caller has picked and not yet saved (e.g. the second port of a site
     * being created).
     *
     * @param  list<int>  $also
     *
     * @throws NoPortAvailableException
     */
    public function allocate(array $also = []): int
    {
        $from = (int) config('server.applications.port_range.from', 3000);
        $to = (int) config('server.applications.port_range.to', 3999);

        $taken = [...$this->appPorts(), ...$also];

        // Containerised databases draw from the same range. Asked here because `listening()`
        // misses a port claimed by a database row whose container has not started yet.
        $databases = DockerDatabase::query()->pluck('port')->all();

        $unavailable = [...$taken, ...$databases, ...$this->listening(), ...$this->registered()];

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
     * Deliberately **weaker** than allocation: skipping every name in /etc/services is right
     * when the panel chooses, wrong when the server's owner does (8080 is `http-alt` and the
     * most common Node port). Only real conflicts are refused: another managed application, or
     * something listening right now.
     *
     * @return 'in_use_by_app'|'in_use'|null
     */
    public function conflict(int $port, ?Application $except = null): ?string
    {
        // The port this application already has is not a conflict, whoever is listening: while
        // it runs, that is the app itself, and `ss` cannot say which process holds a port.
        if ($except?->exists && (int) $except->getOriginal('app_port') === $port) {
            return null;
        }

        // Nor is the port its unit is running on now (a changed, not yet deployed port would
        // make the old one look taken by this same app).
        if ($except?->exists && $this->runningPort($except) === $port) {
            return null;
        }

        $taken = Application::query()
            ->whereNotNull('app_port')
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->pluck('app_port')
            ->all();

        // Every site's second port, this one's included: its own process
        // already listens there, so it cannot be its `app_port` too.
        $internal = Application::query()->whereNotNull('internal_port')->pluck('internal_port')->all();

        if (in_array($port, [...$taken, ...$internal], true)) {
            return 'in_use_by_app';
        }

        return in_array($port, $this->listening(), true) ? 'in_use' : null;
    }

    /**
     * Everything the screen needs to say about a port before it is submitted.
     *
     * Three outcomes: a conflict is a refusal; a port that merely has a name in /etc/services
     * is a **warning** (the user may well mean it), naming the service so they can decide.
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
            // Only when the answer is no.
            'suggested_port' => $conflict === null ? null : $this->suggest(),
        ];
    }

    /**
     * Every port the panel has handed to a site: its `app_port`, and the second port a site
     * like n8n listens on. A stopped site binds neither, so `listening()` alone would reuse them.
     *
     * @return list<int>
     */
    private function appPorts(): array
    {
        return [
            ...Application::query()->whereNotNull('app_port')->pluck('app_port')->all(),
            ...Application::query()->whereNotNull('internal_port')->pluck('internal_port')->all(),
        ];
    }

    /** A free port to offer, or null when the range is exhausted (must not fail the check). */
    private function suggest(): ?int
    {
        try {
            return $this->allocate();
        } catch (NoPortAvailableException) {
            return null;
        }
    }

    /** What `/etc/services` calls a port, if anything. */
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
     * Ports `/etc/services` says belong to something, installed yet or not.
     *
     * Listening-only is not enough: **3306 is MySQL** and sits in the default range, so an app
     * could be given it before MySQL is installed, and installing MySQL later breaks one of
     * the two. `/etc/services` is the distribution's own record and covers services we have
     * never heard of.
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
     * A failed read returns nothing rather than throwing: the unique index and the app's own
     * bind still catch a collision, and a missing `ss` must not block creating an application.
     *
     * @return array<int, int>
     */
    public function listening(): array
    {
        $result = $this->serverOps->run(
            ['ss', '-ltnH'],
            ['feature' => 'application', 'op' => 'port_scan'],
        );

        if ($result->failed()) {
            return [];
        }

        // "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*": the port is the tail of the fourth column.
        preg_match_all('/\s\S*:(\d+)\s/', $result->output(), $matches);

        return array_map('intval', array_unique($matches[1] ?? []));
    }

    /**
     * The PORT the application's systemd unit was last written with, or null. Read from the unit
     * file: it is what the running process got, whatever the row says now.
     */
    private function runningPort(Application $application): ?int
    {
        $path = app(ProcessSupervisor::class)->unitPath($application);

        if (! is_readable($path)) {
            return null;
        }

        return preg_match('/^Environment=PORT=(\d+)$/m', (string) file_get_contents($path), $match) === 1
            ? (int) $match[1]
            : null;
    }
}
