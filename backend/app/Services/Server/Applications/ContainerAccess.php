<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Which containers a Docker app runs, for the Command-line access card (RC-23).
 *
 * Read from the app's **stored compose file**, never from `docker ps`. The card
 * sits on the dashboard, so this runs on every page view, and a shell-out per
 * view is a cost paid to learn what the file already says: the panel always
 * starts it with `-p <project>`, so the names follow from the file alone.
 *
 * A site in Simple mode has no stored file, and gets the one the panel renders
 * for it — the same answer the compose editor shows.
 */
class ContainerAccess
{
    /**
     * What Docker accepts as a container name. A name it would refuse cannot be
     * a running container, and these are pasted into a terminal as part of
     * `sudo docker exec`, by somebody who did not necessarily write the file —
     * so a `container_name` carrying a quote or a `;` is dropped rather than
     * handed to someone else's root shell.
     */
    private const NAME = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/';

    public function __construct(
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * The service that publishes the app's port first, then the rest in the
     * file's own order.
     *
     * @return list<array{service: string, name: string, main: bool}>
     *
     * @throws ProvisioningFailedException a Simple-mode site with no container port
     */
    public function containers(Application $application): array
    {
        $stored = (string) $application->compose;

        $compose = trim($stored) !== ''
            ? $stored
            : $this->containers->generated($application, $this->provisioner->documentRoot($application));

        try {
            // Custom tags so a file using Compose's `!reset`/`!override` still
            // parses; a tagged value is simply not an array below.
            $document = Yaml::parse($compose, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            // A file that does not parse does not run either. Nothing to log in to.
            return [];
        }

        $services = is_array($document) && is_array($document['services'] ?? null)
            ? $document['services']
            : [];

        $project = $this->containers->project($application);
        $appPort = $application->app_port ? (int) $application->app_port : null;

        $main = [];
        $rest = [];

        foreach ($services as $service => $definition) {
            $service = (string) $service;
            $definition = is_array($definition) ? $definition : [];

            // Profiled services are not started by a plain `compose up`, which is
            // all the panel ever runs.
            if (! empty($definition['profiles'])) {
                continue;
            }

            $custom = $definition['container_name'] ?? null;
            $name = is_string($custom) && $custom !== '' ? $custom : "{$project}-{$service}-1";

            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }

            $publishes = $main === [] && $appPort !== null
                && $this->publishes((array) ($definition['ports'] ?? []), $appPort);

            $entry = ['service' => $service, 'name' => $name, 'main' => $publishes];

            if ($publishes) {
                $main[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        return [...$main, ...$rest];
    }

    /**
     * Does one of these port entries publish `$port` on the host?
     *
     * Any host address, not only loopback: the stored file is what the user
     * typed, and the panel binds a bare `8080:80` to 127.0.0.1 when it writes
     * the file to disk — so the column can say either.
     *
     * @param  array<int, mixed>  $ports
     */
    private function publishes(array $ports, int $port): bool
    {
        foreach ($ports as $entry) {
            $published = is_array($entry)
                ? (string) ($entry['published'] ?? '')
                : $this->shortSyntaxHostPort((string) $entry);

            if ($published === '') {
                continue;
            }

            [$from, $to] = array_pad(explode('-', $published, 2), 2, null);
            $to ??= $from;

            if (ctype_digit((string) $from) && ctype_digit((string) $to)
                && $port >= (int) $from && $port <= (int) $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host side of `[ip:]host:container[/proto]`, or '' when the entry only
     * names a container port and so publishes nothing fixed.
     */
    private function shortSyntaxHostPort(string $entry): string
    {
        $entry = preg_replace('#/(tcp|udp|sctp)$#i', '', trim($entry)) ?? '';

        $lastColon = strrpos($entry, ':');

        if ($lastColon === false) {
            return '';
        }

        // Drop the container side; what is left is `host` or `ip:host`, and an
        // IPv6 address ends in `]` before its own last colon.
        $left = substr($entry, 0, $lastColon);
        $hostColon = strrpos($left, ':');

        return $hostColon === false ? $left : substr($left, $hostColon + 1);
    }
}
