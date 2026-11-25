<?php

namespace App\Services\Server\Firewall;

use App\Services\Server\ServerOps;

/**
 * What is actually listening on the server.
 *
 * Lets the firewall screen tell an open port with a service behind it from an open port with
 * nothing there.
 *
 * Only the program name needs root (the kernel reveals a socket's owner only to that owner).
 * When unavailable it is null, never inferred from the port number: a wrong service name is
 * worse than none.
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

        // A service bound to both IPv4 and IPv6 is one thing listening, not two.
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

        // UDP sockets show UNCONN rather than LISTEN.
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
            // Loopback and link-local addresses are reachable only from the machine itself.
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

        // users:(("nginx",pid=1073,fd=6)) — absent for processes we do not own.
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
        // Deduped on port + protocol + reachability: 0.0.0.0 and :: collapse, but a loopback-only
        // twin does not hide a public one.
        return $entry['protocol'].':'.$entry['port'].':'.($entry['public'] ? 'public' : 'local');
    }
}
