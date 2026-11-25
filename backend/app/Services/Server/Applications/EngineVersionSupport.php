<?php

namespace App\Services\Server\Applications;

use App\Services\Server\Databases\DatabaseManager;

/**
 * Whether a database engine on this server is new enough for an application that declares a
 * minimum, so the panel can refuse early instead of the app's own installer dying on a
 * missing feature.
 *
 * The minimum is declared per installer, not as one central floor: the types accepting
 * PostgreSQL disagree (Craft 13, Joomla 12, Moodle 14, Nextcloud 14).
 *
 * It cannot fire on a panel-installed database (Ubuntu's oldest supported release carries 14).
 * It exists for a server adopted with a hand-installed cluster and for external connections
 * set through `PUT /databases/connections/{engine}`.
 */
class EngineVersionSupport
{
    /** @var array<string, string|null> */
    private array $versions = [];

    public function __construct(private DatabaseManager $databases) {}

    /**
     * True when the engine meets the application's minimum, or there is no minimum.
     *
     * Also true when the version cannot be read: an unanswered question is not "too old", and
     * refusing would stop the server hosting any site. The install then fails the old way.
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
     * Only the leading number is a version: PostgreSQL answers `16.4 (Ubuntu 16.4-0ubuntu0...)`
     * on some builds and MySQL appends suffixes, which `version_compare` would mis-compare.
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
