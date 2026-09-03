<?php

namespace App\Services\Server\Databases;

use Throwable;

/**
 * Which SQL engine is on this box, and can the panel actually talk to it.
 *
 * Two questions, deliberately separated, because conflating them produced a
 * screen that told the user a lie. `MysqlSettings` used to answer "is there an
 * engine" by running `SELECT 1` through the stored admin connection — so on a
 * box with MariaDB running and installed, but whose stored credentials do not
 * work, the panel said *"No MySQL or MariaDB server is running on this
 * machine"*. The server was running the whole time; the panel simply could not
 * log in to it.
 *
 * Presence is answered without a credential — a systemd unit or the engine's
 * own config directory — because neither can be wrong for the reason
 * authentication can. Reachability can only be answered by trying, since a
 * password is only known to be right when it works. Storing a flag for the
 * second is not possible, which is the whole reason these are two methods and
 * not one.
 *
 * The presence probe is deliberately cheap and boring. It is not a claim that
 * the engine is healthy, only that it exists — the difference between "not
 * installed" and "installed, and something is wrong" is what the operator
 * needs in order to know whether to install something or fix something.
 */
class SqlEngineLocator
{
    /**
     * MariaDB first: on a box carrying both client packages the running server
     * is far more often MariaDB, and the config directories can both exist.
     */
    private const ENGINES = ['mariadb', 'mysql'];

    public function __construct(private DatabaseManager $databases) {}

    /**
     * The SQL engine installed on this box, or null when there is none.
     *
     * No credentials involved. A stopped engine still counts as present:
     * stopping a database is not the same as not having one, and telling
     * somebody to install what they already have is the worse error.
     */
    public function present(): ?string
    {
        foreach (self::ENGINES as $engine) {
            if (! in_array($engine, $this->databases->engineNames(), true)) {
                continue;
            }

            // Config directory first, and it is usually the only check that
            // runs. It is a plain `is_dir` on a world-readable path: no sudo,
            // no subprocess, nothing that can fail for a reason unrelated to
            // the question being asked.
            if ($this->configDirectoryExists($engine) || $this->unitFileExists($engine)) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * Can the panel authenticate and run a statement right now?
     *
     * Never throws. This is asked while rendering a settings page, and an
     * exception from a probe would take out the whole screen — including the
     * other groups, which have nothing to do with the database.
     */
    public function reachable(string $engine): bool
    {
        try {
            $resolved = $this->databases->engine($engine);

            return $resolved instanceof SqlEngine && $resolved->available();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Is there a systemd unit file for this engine, or for the other spelling?
     *
     * Read off the disk rather than asked of `systemctl`. That earlier version
     * shipped a real bug: `systemctl cat` goes through ServerOps and therefore
     * `sudo`, and on a panel running as `www-data` the grant did not cover it.
     * The denial was then *logged*, the log file was not writable by that user,
     * and the logging exception propagated out of a method whose whole job is
     * to answer true or false. So a probe that could not answer the question
     * prevented the one that could — the directory check below, which had the
     * right answer all along — from ever running.
     *
     * Both unit names are tried. MariaDB ships `mariadb.service` and a
     * `mysql.service` alias, and which one a box has depends on how it was
     * installed; asking for one spelling only is a coin flip.
     */
    private function unitFileExists(string $engine): bool
    {
        $names = $engine === 'mariadb' ? ['mariadb', 'mysql'] : ['mysql', 'mariadb'];
        $directories = (array) config('server.systemd_unit_dirs', [
            '/etc/systemd/system',
            '/lib/systemd/system',
            '/usr/lib/systemd/system',
        ]);

        foreach ($directories as $directory) {
            foreach ($names as $name) {
                if (is_file(rtrim((string) $directory, '/').'/'.$name.'.service')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The engine's own config directory — the same signal the web-server
     * detection uses, and for the same reason: a package leaves it behind
     * whether or not the service happens to be up.
     */
    private function configDirectoryExists(string $engine): bool
    {
        $dir = (string) config("server.databases.engines.{$engine}.config_dir");

        return $dir !== '' && is_dir($dir);
    }
}
