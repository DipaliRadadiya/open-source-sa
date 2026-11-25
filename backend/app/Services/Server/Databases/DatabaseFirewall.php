<?php

namespace App\Services\Server\Databases;

use App\Contracts\Firewall;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseUser;
use App\Models\FirewallRule;
use Illuminate\Support\Facades\Log;

/**
 * Opens the engine port in the firewall when a DB user is given remote access (reuses the Firewall
 * feature), and closes it again when the last user that needed it is gone.
 *
 * Rules carry the `db_user` origin, which the user may delete; `default` rules cannot be removed
 * while the firewall is on. "Needed" is counted across engines that share a port (MySQL and
 * MariaDB are both 3306).
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
            // A row for a rule ufw refused would exist only on the Firewall screen, and ufw
            // cannot delete what it never had, so nobody could remove it.
            if ($rule->wasRecentlyCreated) {
                $rule->delete();
            }

            throw new DatabaseOperationException($result->reference, $result->busy, $result->staleLock);
        }
    }

    /**
     * Close the rule a user with this access had, unless another user still needs it. Call after
     * the user row is gone or changed.
     *
     * Only rules this class opened (`db_user` origin) are touched: an administrator's own rule
     * for the same port and address is theirs to keep. Never fatal: the user delete already
     * happened on the engine; the rule stays listed and removable.
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
