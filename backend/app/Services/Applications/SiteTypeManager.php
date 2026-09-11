<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;
use App\Services\Server\Applications\EngineVersionSupport;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Databases\DatabaseManager;

/**
 * The application catalog — resolves site types and describes them for the
 * card grid.
 *
 * Availability is decided by what the server can actually run, the same rule
 * Services and Databases already follow. Unavailable types are still returned,
 * flagged and greyed rather than hidden: a card the user can't use yet is how
 * they discover the runtime exists, and it becomes the install button once the
 * runtime-install feature ships.
 */
class SiteTypeManager
{
    /**
     * Engine name => can this server serve an application from it.
     *
     * @var array<string, bool>
     */
    private array $usableEngines = [];

    public function __construct(
        private ServerCapabilities $capabilities,
        private InstallerManager $installers,
        private DatabaseManager $databases,
        private EngineVersionSupport $versions,
    ) {}

    /**
     * @return array<int, SiteType>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), (array) config('server.site_types', []));
    }

    public function find(string $name): ?SiteType
    {
        foreach ($this->all() as $type) {
            if ($type->name() === $name) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(fn (SiteType $type) => $type->name(), $this->all());
    }

    /**
     * The card grid: every type, with its field schema and whether this server
     * can run it.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Why a card is greyed, as a value the UI can branch on.
     *
     * Deliberately three separate codes rather than one "blocked": each has a
     * different way out. A runtime can be installed from the card itself, a
     * database from the Databases screen, and a web server's refusal has no way
     * out at all — offering one would be a lie.
     */
    public const BLOCKED_RUNTIME = 'runtime';

    public const BLOCKED_DATABASE = 'database';

    public const BLOCKED_WEB_SERVER = 'web_server';

    public function catalog(): array
    {
        return array_map(function (SiteType $type) {
            $profile = $type->servingProfile();
            $blocked = $this->unavailable($type);
            $available = $blocked === null;

            return [
                'name' => $type->name(),
                'title' => __("application.types.{$type->name()}.title"),
                'tagline' => __("application.types.{$type->name()}.tagline"),
                'icon' => $type->icon(),
                'category' => $type->category(),
                'popular' => $type->popular(),
                // Internal: how we build it. The user is never asked to choose.
                'method' => $type->method(),
                'serving_profile' => $profile,
                'needs_database' => $type->needsDatabase(),
                // Which engines, not just whether. `needs_database` alone left
                // the frontend inferring the list from the type's name, which
                // was right only by coincidence — there happen to be two engine
                // lists in the whole catalog today, and the first application
                // accepting both MongoDB and MySQL would have made that
                // inference silently wrong. An API that makes a client guess
                // something it already holds is the API's bug.
                //
                // Empty when the type needs no database, so "no constraint" and
                // "no database" are the same value rather than a null to
                // special-case.
                'accepted_engines' => $this->acceptedEngines($type),
                'available' => $available,
                'unavailable_reason' => $blocked['reason'] ?? null,
                // The same block as a stable value: 'runtime' | 'database' |
                // 'web_server', null when available. `unavailable_reason` is
                // the sentence to show; this is the one to branch on, and the
                // two must never be read the other way round.
                'unavailable_code' => $blocked['code'] ?? null,
                // The runtime that would have to be installed to make this card
                // usable, so an unavailable card can offer to fix itself rather
                // than just being greyed out. Null when the card is available.
                //
                // Named for what it holds. It was `installable`, which read as
                // "this app installs itself" — a different question, answered
                // by `has_installer` below, and one the grid genuinely needs
                // in order to tell "click and get WordPress" apart from "click
                // and get an empty directory".
                // Null when nothing installable would fix it — a type this web
                // server does not support is not an "install a runtime" prompt.
                'installable_runtime' => $blocked['runtime'] ?? null,
                'has_installer' => $this->installers->installerForType($type->name()) !== null,
                // So the version picker can offer only what this application
                // runs on. The create endpoint refuses the rest either way —
                // this is here so the form does not present a choice that is
                // going to be rejected, the same rule the card grid follows by
                // reporting `available` instead of failing at submit.
                'node_version_range' => $type->supportedNodeRange(),
                'php_version_range' => $type->supportedPhpRange(),
                'fields' => [...$type->fields(), ...$this->engineField($type)],
            ];
        }, $this->all());
    }

    /**
     * Why this server cannot offer a site type, or null when it can.
     *
     * One method rather than two checks, because the card grid and the create
     * endpoint both need this answer and must not be able to disagree — a card
     * shown as available that then fails validation is worse than either.
     *
     * Carries a `code` as well as the sentence. The sentence is for reading and
     * is translated; anything deciding what to *do* about the block needs
     * something stable to match on. Without one the card grid was inferring the
     * database case from `needs_database` plus the absence of an installable
     * runtime — which its own comment notes would misread a web-server refusal
     * as a missing database, and `site_types` exists precisely so a driver can
     * refuse one.
     *
     * @return array{code: string, reason: string, runtime: ?string}|null
     */
    public function unavailable(SiteType $type): ?array
    {
        $runtime = $this->requiredRuntime($type->servingProfile());

        if ($runtime !== null && ! $this->capabilities->supports($runtime)) {
            return [
                'code' => self::BLOCKED_RUNTIME,
                'reason' => __("application.unavailable.{$runtime}"),
                'runtime' => $runtime,
            ];
        }

        $missing = $this->missingEngines($type);

        if ($missing !== null) {
            return [
                'code' => self::BLOCKED_DATABASE,
                'reason' => __('application.unavailable.database', ['engines' => $missing]),
                'runtime' => null,
            ];
        }

        $webServer = (string) $this->capabilities->webServer();
        $allowed = (array) config("server.web_server_drivers.{$webServer}.site_types", []);

        // An empty list means no restriction, which is the case for nginx and
        // Apache: they serve everything we ship. A web server lists types only
        // when it supports some and not others.
        if ($allowed !== [] && ! in_array($type->name(), $allowed, true)) {
            return [
                'code' => self::BLOCKED_WEB_SERVER,
                'reason' => __('application.unavailable.web_server', [
                    'web_server' => (string) config("server.web_server_drivers.{$webServer}.label", $webServer),
                ]),
                // Nothing to install would fix this, so the card must not
                // offer to fix itself.
                'runtime' => null,
            ];
        }

        return null;
    }

    /**
     * The engines this type needs and this server hasn't got, as a readable
     * list — or null when it needs none, or has one.
     *
     * **Asked of every type that needs a database.** It used to return early
     * for anything accepting MySQL or MariaDB, which is nearly the whole
     * catalog, so only NodeBB — MongoDB alone — was ever checked. On a
     * MongoDB-only server every SQL-backed type reported itself available,
     * took a filled-in form, and failed at provisioning.
     *
     * That early return was argued for, and the argument is worth answering
     * rather than deleting silently. It said greying ten cards on a server
     * whose engine "is simply not up yet" would hide the catalog rather than
     * explain it, and that `create_database` would fail with an accurate
     * message anyway. Both were reasonable when the alternative was a card
     * that vanished with no explanation. Neither survives the case that
     * actually bites: an engine that is up, healthy, and permanently unable to
     * serve this application. No amount of waiting fixes MongoDB for
     * WordPress, and an accurate message at `create_database` arrives after
     * the user has filled in the form.
     */
    private function missingEngines(SiteType $type): ?string
    {
        $accepted = $this->acceptedEngines($type);

        if ($accepted === []) {
            return null;
        }

        foreach ($accepted as $engine) {
            if ($this->engineUsableFor($type, $engine)) {
                return null;
            }
        }

        return implode(' / ', array_map(
            fn (string $engine) => (string) config("server.databases.engines.{$engine}.label", $engine),
            $accepted,
        ));
    }

    /**
     * The database engines this type can be installed against.
     *
     * Empty for a type that needs no database and for one with no installer —
     * a custom PHP site brings its own arrangements, and the panel has no list
     * to offer for it. Read from the installer rather than the SiteType because
     * the installer is what provisioning asks, so the catalog cannot advertise
     * a pairing that creating would refuse.
     *
     * @return list<string>
     */
    private function acceptedEngines(SiteType $type): array
    {
        $installer = $this->installers->installerForType($type->name());

        if ($installer === null || ! $installer->needsDatabase()) {
            return [];
        }

        return array_values($installer->acceptedEngines());
    }

    /**
     * Can this server actually serve an application from `$engine`?
     *
     * Memoized for the life of the request, and that is not an optimisation —
     * it is what makes the check above affordable. `available()` is a live
     * `SELECT 1` through `sudo`, and the catalog asks this once per type: the
     * check that used to run for one type now runs for eleven, which without
     * this would be dozens of subprocesses on a page that renders a grid of
     * cards.
     *
     * ⚠️ `available()` answers "did the query succeed", so an engine that is
     * absent, stopped, or one the panel is not permitted to ask are all `false`
     * here. That is right for this question — none of the three can serve an
     * application right now — but it means a broken sudo grant greys the whole
     * catalog. The three states the card grid distinguishes come from
     * `DatabaseManager::capabilities()`, which separates them properly.
     */
    /**
     * A database-engine picker, but only where there is genuinely a choice.
     *
     * Zero or one usable engine means no field at all: a dropdown with one
     * option is a decision the user cannot make, on seventeen of the eighteen
     * types, and the create form is long enough already.
     *
     * Two or more means the panel would otherwise be choosing silently — and
     * that choice is permanent. Nothing moves a forum from MongoDB to
     * PostgreSQL afterwards; it would be delete and start again.
     *
     * The pairs that make this real only appeared with PostgreSQL. Eight types
     * list `mysql, mariadb`, which reads like a choice and is not: the two
     * cannot coexist — they fight over 3306 and the installer refuses the
     * second — so exactly one is ever usable and this returns nothing for
     * them. NodeBB's `mongodb, postgresql` is the first pairing a server can
     * genuinely have both halves of.
     *
     * Built here rather than in the site type because only this class knows
     * what the *server* has. The type knows what the application supports.
     *
     * @return array<int, array<string, mixed>>
     */
    private function engineField(SiteType $type): array
    {
        $usable = array_values(array_filter(
            $this->acceptedEngines($type),
            fn (string $engine): bool => $this->engineUsableFor($type, $engine),
        ));

        if (count($usable) < 2) {
            return [];
        }

        return [[
            'name' => 'database_engine',
            'label' => __('application.fields.database_engine'),
            'type' => 'select',
            'required' => false,
            'advanced' => false,
            // The first accepted engine that this server has, which is exactly
            // what provisioning falls back to when the field is absent. The
            // form and the fallback cannot disagree, because they are the same
            // list in the same order.
            'default' => $usable[0],
            'options' => array_map(fn (string $engine): array => [
                'value' => $engine,
                'label' => (string) config("server.databases.engines.{$engine}.label", $engine),
            ], $usable),
        ]];
    }

    /**
     * Usable *for this application*: installed, reachable, and new enough.
     *
     * The version belongs here rather than in {@see engineUsable()} because it
     * is the only one of the three that depends on which application is
     * asking — a PostgreSQL 13 is unusable for Moodle and perfectly fine for
     * NodeBB, on the same server in the same request. Keeping them apart is
     * what lets the reachability answer stay memoized across every type in the
     * catalog while the version answer varies by type.
     */
    private function engineUsableFor(SiteType $type, string $engine): bool
    {
        if (! $this->engineUsable($engine)) {
            return false;
        }

        $installer = $this->installers->installerForType($type->name());

        return $installer === null
            || $this->versions->meets($engine, $installer->minimumEngineVersions());
    }

    private function engineUsable(string $engine): bool
    {
        if (! array_key_exists($engine, $this->usableEngines)) {
            $this->usableEngines[$engine] = in_array($engine, $this->databases->engineNames(), true)
                && $this->databases->engine($engine)->available();
        }

        return $this->usableEngines[$engine];
    }

    /**
     * Which runtime a serving profile needs — null when it needs none, so
     * static sites and reverse proxies work on any server.
     */
    public function requiredRuntime(string $profile): ?string
    {
        return match ($profile) {
            'php' => 'php',
            'node' => 'node',
            default => null,
        };
    }
}
