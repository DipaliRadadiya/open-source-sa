<?php

namespace App\Services\Server\Databases;

use App\Services\Server\ServerOps;

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

    public function __construct(
        private DatabaseManager $databases,
        private ServerOps $serverOps,
    ) {}

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

            if ($this->unitKnown($engine) || $this->configDirectoryExists($engine)) {
                return $engine;
            }
        }

        return null;
    }

    /** Can the panel authenticate and run a statement right now? */
    public function reachable(string $engine): bool
    {
        $resolved = $this->databases->engine($engine);

        return $resolved instanceof SqlEngine && $resolved->available();
    }

    /**
     * Does systemd know a unit for this engine?
     *
     * `list-unit-files` rather than `is-active`, because a stopped engine is
     * still an installed one and this method is only asked about existence.
     */
    private function unitKnown(string $engine): bool
    {
        $unit = null;

        foreach ((array) config('server.services', []) as $service) {
            if (($service['key'] ?? null) === $engine) {
                $unit = $service['unit'] ?? null;
                break;
            }
        }

        if ($unit === null) {
            return false;
        }

        return $this->serverOps->run(
            ['systemctl', 'cat', $unit.'.service'],
            ['feature' => 'database', 'engine' => $engine, 'op' => 'unit_present'],
        )->ok;
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
