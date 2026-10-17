<?php

namespace App\Services\Server\Databases;

use PDO;
use Throwable;

/**
 * Does this password sign root in to MySQL/MariaDB?
 *
 * Asked from PHP itself, as the panel's own OS user — **not** through the
 * elevated client. ServerOps runs `mysql` as OS root, and on MariaDB root's
 * login is "password OR unix_socket": as OS root the socket half lets anyone
 * in, so a check made that way passes for any password at all. As the panel
 * user the socket half is refused and only the password can succeed.
 */
class SqlPasswordCheck
{
    public function works(string $engine, string $password): bool
    {
        $socket = (string) config("server.databases.engines.{$engine}.default_socket", '');

        if ($socket === '') {
            return false;
        }

        try {
            $pdo = new PDO('mysql:unix_socket='.$socket, 'root', $password, [
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return $pdo->query('SELECT 1')?->fetchColumn() == 1;
        } catch (Throwable) {
            return false;
        }
    }
}
