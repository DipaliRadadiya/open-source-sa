<?php

namespace App\Services\Server\Firewall;

use App\Services\Server\ServerOps;

/**
 * What is actually listening on the server.
 *
 * A firewall screen without this is a list of rules about nothing: it cannot
 * tell "port 8080 is open and a service is behind it" from "port 8080 is open
 * and nothing has been there for months", and those want opposite actions.
 *
 * The program name is the one field that needs root — the kernel only reveals
 * which process owns a socket to that process's owner. Unprivileged, the port,
 * protocol and bind address all read fine, which is enough for the two
 * questions the screen is really asking. Where the name is unavailable it is
 * reported as null rather than inferred from the port number: a firewall
 * screen naming the wrong service is worse than one naming none.
 */
class ListeningPorts
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $result = $this->serverOps->run(
            // -H drops the header so there is no line to skip and mis-skip.
            ['ss', '-tulpnH'],
            ['feature' => 'firewall', 'op' => 'listening'],
        );

        if (! $result->ok) {
            return [];
        }

        $listening = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $entry = $this->parse($line);

            if ($entry !== null) {
                $listening[$this->key($entry)] = $entry;
            }
        }

        // A service bound to both IPv4 and IPv6 is one thing listening, not
        // two — the screen should say "port 443" once.
        return array_values($listening);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $line): ?array
    {
        $fields = preg_split('/\s+/', trim($line));

        if ($fields === false || count($fields) < 5) {
            return null;
        }

        [$protocol, $state] = [$fields[0], $fields[1]];

        // UDP sockets show UNCONN rather than LISTEN; both are "something is
        // there", which is the distinction that matters here.
        if (! in_array($state, ['LISTEN', 'UNCONN'], true)) {
            return null;
        }

        $address = $fields[4];
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $bind = trim(substr($address, 0, (int) strrpos($address, ':')), '[]');

        if ($port === 0) {
            return null;
        }

        return [
            'port' => $port,
            'protocol' => str_starts_with($protocol, 'udp') ? 'udp' : 'tcp',
            'address' => $bind,
            // Loopback and link-local resolvers are reachable only from the
            // machine itself, so no firewall rule can expose them — the screen
            // should not imply otherwise.
            'public' => $this->isPublic($bind),
            'program' => $this->program($fields),
        ];
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function program(array $fields): ?string
    {
        $tail = implode(' ', array_slice($fields, 5));

        // users:(("nginx",pid=1073,fd=6)) — absent entirely for processes we
        // do not own, which unprivileged is nearly all of them.
        return preg_match('/users:\(\("([^"]+)"/', $tail, $matches) === 1 ? $matches[1] : null;
    }

    private function isPublic(string $bind): bool
    {
        $bind = strtolower($bind);

        if ($bind === '*' || $bind === '0.0.0.0' || $bind === '::') {
            return true;
        }

        // 127.0.0.53%lo and friends — an interface-scoped loopback address.
        $host = explode('%', $bind)[0];

        return ! str_starts_with($host, '127.')
            && $host !== '::1'
            && ! str_starts_with($host, 'fe80:');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function key(array $entry): string
    {
        // Deduped on port + protocol + reachability, so the same service on
        // 0.0.0.0 and :: collapses but a loopback-only twin does not hide a
        // public one.
        return $entry['protocol'].':'.$entry['port'].':'.($entry['public'] ? 'public' : 'local');
    }
}
