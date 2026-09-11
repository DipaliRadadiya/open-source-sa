<?php

namespace App\Services\Server\Applications;

use App\Services\Server\Databases\DatabaseManager;

/**
 * Whether a database engine on this server is new enough for an application
 * that declares a minimum.
 *
 * The failure this prevents is the one `acceptedEngines()` was built for, one
 * level down: a site provisions against a database the application supports in
 * principle and refuses in practice, then dies inside the application's own
 * installer with an error about a missing feature — long past the point where
 * the panel could have said something useful.
 *
 * 📌 **Per installer, not one central floor.** The four types that accept
 * PostgreSQL do not agree on a number — Craft names 13, Joomla 12, Moodle 14
 * and Nextcloud 14 — so a single shared minimum would have to be the highest
 * of them, and would then refuse Craft and Joomla a cluster both run on
 * perfectly well. Declaring it on the installer is also what lets a type with
 * no such requirement stay out of it entirely rather than inheriting someone
 * else's floor.
 *
 * ⚠️ In practice this cannot fire on a database the panel installed: the panel
 * installs Ubuntu's `postgresql` package, and its oldest supported release
 * (22.04) carries 14. It exists for the two ways an older one arrives anyway —
 * a server adopted with a hand-installed cluster, and a connection pointed at
 * an external database through `PUT /databases/connections/{engine}`.
 */
class EngineVersionSupport
{
    /** @var array<string, string|null> */
    private array $versions = [];

    public function __construct(private DatabaseManager $databases) {}

    /**
     * True when the engine meets the application's minimum, or when there is
     * no minimum to meet.
     *
     * 🔴 Also true when the version cannot be read. An engine that will not
     * answer is a question we could not ask, not an answer of "too old", and
     * refusing on it would turn an unreadable database into a server that
     * cannot host a site — the same mistake as reading an empty PHP version
     * list as "no PHP installed". The install then fails the way it did
     * before this class existed, which is the honest outcome.
     *
     * @param  array<string, string>  $minimums  engine name => minimum version
     */
    public function meets(string $engine, array $minimums): bool
    {
        $minimum = $minimums[$engine] ?? null;

        if ($minimum === null) {
            return true;
        }

        $version = $this->version($engine);

        return $version === null || version_compare($version, $minimum, '>=');
    }

    /** The minimum this application needs, only when the engine falls short. */
    public function shortfall(string $engine, array $minimums): ?string
    {
        return $this->meets($engine, $minimums) ? null : ($minimums[$engine] ?? null);
    }

    /** What the engine reports, as a bare version number. */
    public function version(string $engine): ?string
    {
        if (! array_key_exists($engine, $this->versions)) {
            $this->versions[$engine] = $this->read($engine);
        }

        return $this->versions[$engine];
    }

    /**
     * PostgreSQL answers `SHOW server_version` with `16.4` on some builds and
     * `16.4 (Ubuntu 16.4-0ubuntu0.24.04.2)` on others, and MySQL appends its
     * own suffixes. Only the leading number is a version; handing the rest to
     * `version_compare` compares packaging strings.
     */
    private function read(string $engine): ?string
    {
        $reported = $this->databases->engine($engine)->version();

        if ($reported === null || ! preg_match('/^\d+(\.\d+)*/', trim($reported), $m)) {
            return null;
        }

        return $m[0];
    }
}
