<?php

namespace App\Services\Server\Databases;

use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Runs SQL as MySQL/MariaDB root.
 *
 * First over the unix socket with no password: on MariaDB 10.4+ and MySQL 8 on Ubuntu,
 * root@localhost authenticates by being OS root, which ServerOps already is.
 *
 * Then with the saved or supplied root password: MySQL drops socket login once root has
 * a password, and a server moved from v7 never had it.
 *
 * The password goes in a 0600 client option file, never on argv; SQL goes over stdin.
 */
class SqlRootAccess
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @param  list<string>  $flags  extra client flags, e.g. --batch
     * @param  list<string>  $passwords  more root passwords to try after the stored one
     */
    public function run(string $engine, string $sql, string $op, array $flags = [], array $passwords = []): ServerOpsResult
    {
        $context = ['feature' => 'database', 'engine' => $engine, 'op' => $op];

        $stored = DatabaseConnection::query()->where('engine', $engine)->first()?->root_password;
        $candidates = array_values(array_unique(array_filter(
            [$stored, ...$passwords],
            fn ($password) => is_string($password) && $password !== '',
        )));

        $result = $this->serverOps->run(
            [$this->client($engine), '--protocol=socket', '--user=root', ...$flags],
            $context,
            timeout: 60,
            input: $sql,
            // With a password to fall back to, a refused socket login is normal on MySQL,
            // not an error to log. A real SQL error fails the retry too and is logged there.
            expectedExitCodes: $candidates === [] ? [] : [1],
        );

        if (! $result->failed()) {
            return $result;
        }

        foreach ($candidates as $password) {
            $file = $this->authFile($engine, $password);

            try {
                $retry = $this->serverOps->run(
                    [$this->client($engine), '--defaults-extra-file='.$file, '--protocol=socket', ...$flags],
                    $context,
                    timeout: 60,
                    input: $sql,
                );
            } finally {
                @unlink($file);
            }

            if (! $retry->failed()) {
                return $retry;
            }
        }

        return $result;
    }

    private function client(string $engine): string
    {
        return (string) config("server.databases.engines.{$engine}.client", 'mysql');
    }

    private function authFile(string $engine, string $password): string
    {
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $file = $dir.'/db-'.bin2hex(random_bytes(8)).'.cnf';

        $lines = ['[client]', 'user="root"', 'password="'.addslashes($password).'"'];
        $socket = (string) config("server.databases.engines.{$engine}.default_socket", '');
        if ($socket !== '') {
            $lines[] = 'socket="'.addslashes($socket).'"';
        }

        // Created 0600 before the password is written, not chmodded after.
        touch($file);
        @chmod($file, 0600);
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }
}
