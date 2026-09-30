<?php

namespace App\Services\Server\Databases;

use App\Contracts\Firewall;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseUser;
use App\Models\FirewallRule;
use Illuminate\Support\Facades\Log;

/**
 * Opens the engine port in the firewall when a DB user is given remote access
 * (reuses the Firewall feature), and closes it again when the last user that
 * needed it is gone.
 *
 * It used to be open-only, and the rule it opened was seeded as `default` —
 * the origin of the panel's own SSH/HTTP rules, which cannot be removed while
 * the firewall is on. So deleting the remote user (or its database) left
 * 3306 open to that address with no way to close it short of switching the
 * whole firewall off (found on the nginx test box, 2026-09-26). The rules now
 * carry their own origin, `db_user` — the value the rule list's origin filter
 * already offered — which the user may delete, and release() closes one once
 * no remaining user needs it. "Needs" is counted across
 * engines that share a port — MySQL and MariaDB are both 3306.
 */
class DatabaseFirewall
{
    public function __construct(private Firewall $firewall) {}

    /**
     * @param  string  $preference  localhost | remote | anywhere
     * @param  string  $host  IP/CIDR for `remote`; ignored otherwise
     */
    public function sync(string $engine, string $preference, string $host): void
    {
        if ($preference === 'localhost') {
            return; // nothing to open
        }

        if (! $this->firewall->status()['enabled']) {
            return; // no firewall to sync
        }

        $rule = FirewallRule::query()->firstOrCreate(
            $this->identity($engine, $preference, $host),
            ['origin' => FirewallRule::ORIGIN_DATABASE, 'description' => strtoupper($engine).' remote access'],
        );

        $result = $this->firewall->apply($rule);

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference, $result->busy, $result->staleLock);
        }
    }

    /**
     * Close the rule a user with this access had, unless another user still
     * needs it. Call after the user row is gone or changed.
     *
     * Only rules this class opened (`db_user` origin) are touched: a rule the
     * administrator wrote for the same port and address is theirs to keep.
     * Never fatal — a user delete that already happened on the engine must not
     * be reported as failed because ufw could not be told; the rule is left in
     * place, still listed and still removable.
     */
    public function release(string $engine, string $preference, ?string $host): void
    {
        if ($preference === 'localhost') {
            return;
        }

        $rule = FirewallRule::query()
            ->where($this->identity($engine, $preference, $host))
            ->where('origin', FirewallRule::ORIGIN_DATABASE)
            ->first();

        if ($rule === null || $this->stillNeeded($rule)) {
            return;
        }

        $result = $this->firewall->remove($rule);

        if ($result->failed()) {
            Log::warning('database remote-access rule could not be closed', [
                'feature' => 'database',
                'rule' => $rule->getKey(),
                'reference' => $result->reference,
            ]);

            return;
        }

        $rule->delete();
    }

    /**
     * @return array{port_from: int, port_to: null, protocol: string, action: string, source_ip: ?string}
     */
    private function identity(string $engine, string $preference, ?string $host): array
    {
        return [
            'port_from' => $this->port($engine),
            'port_to' => null,
            'protocol' => 'tcp',
            'action' => 'allow',
            // `anywhere` = any source.
            'source_ip' => $preference === 'remote' ? $host : null,
        ];
    }

    private function stillNeeded(FirewallRule $rule): bool
    {
        return DatabaseUser::query()
            ->with('database:id,engine')
            ->whereIn('connection_preference', ['remote', 'anywhere'])
            ->get()
            ->contains(fn (DatabaseUser $user) => $user->database !== null
                && $this->port($user->database->engine) === $rule->port_from
                && ($user->connection_preference === 'remote' ? $user->host : null) === $rule->source_ip);
    }

    private function port(string $engine): int
    {
        return (int) config("server.databases.engines.{$engine}.default_port");
    }
}
