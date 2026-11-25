<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\Types\DockerSiteType;
use App\Services\Applications\Types\RecipeSiteType;
use App\Services\Recipes\Recipe;
use App\Services\Recipes\RecipePresenter;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\EngineVersionSupport;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * The application catalog — resolves site types and describes them for the
 * card grid.
 *
 * Availability is decided by what the server can actually run. Unavailable types are still
 * returned, flagged rather than hidden, so a card the user can't use yet can offer to fix itself.
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
        private PhpVersionManager $phpVersions,
        private PhpRuntime $php,
        private RecipeRegistry $recipes,
    ) {}

    /**
     * @return array<int, SiteType>
     */
    public function all(): array
    {
        $types = [];
        foreach ((array) config('server.site_types', []) as $class) {
            $types[] = app($class);
            if ($class === DockerSiteType::class) {
                array_push($types, ...array_map(
                    fn (Recipe $recipe) => new RecipeSiteType($recipe),
                    array_values($this->recipes->all()),
                ));
            }
        }

        $names = array_map(fn (SiteType $type) => $type->name(), $types);
        if (count($names) !== count(array_unique($names))) {
            throw new \LogicException('Duplicate site type names: '.implode(',', array_diff_assoc($names, array_unique($names))));
        }

        return $types;
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
     * Why a card is greyed, as a value the UI can branch on.
     *
     * Separate codes rather than one "blocked": each has a different way out. A runtime can be
     * installed from the card, a database from the Databases screen, and a web server's refusal
     * has no way out at all.
     */
    public const BLOCKED_RUNTIME = 'runtime';

    public const BLOCKED_DATABASE = 'database';

    public const BLOCKED_WEB_SERVER = 'web_server';

    /**
     * The stack does not host this kind of application at all.
     *
     * Separate from `BLOCKED_RUNTIME` because there is nothing to install: a Docker box has PHP
     * (the panel is PHP), so the runtime check would pass for WordPress on a server that will
     * never serve a PHP site.
     */
    public const BLOCKED_STACK = 'stack';

    public const BLOCKED_DEPRECATED = 'deprecated';

    /**
     * PHP is here, but no version this application runs on. The way out is the
     * PHP screen when the package index has one, and none when it does not —
     * the reason says which.
     */
    public const BLOCKED_PHP_VERSION = 'php_version';

    /**
     * The database engine is here and running, but older than this application
     * runs on (e.g. Moodle and Mautic on Ubuntu 24.04's MySQL 8.0).
     *
     * Not `BLOCKED_DATABASE`: that one sends the user to install an engine, and MySQL and MariaDB
     * cannot share a server, so the install offer would be a dead end.
     */
    public const BLOCKED_DATABASE_VERSION = 'database_version';

    /**
     * The card grid: every type, with its field schema and whether this server
     * can run it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        // Types this stack will never host and retired recipes are left out entirely: nothing
        // installs a way out and it is true of every card at once. `unavailable()` still answers
        // for them, so the create endpoint refuses a filtered type as before.
        //
        // `array_values` is load-bearing: `array_filter` keeps keys, `json_encode` would emit an
        // object instead of an array and the frontend's `z.array()` would reject the payload.
        $types = array_values(array_filter(
            $this->all(),
            fn (SiteType $type) => ! in_array($this->unavailable($type)['code'] ?? null, [self::BLOCKED_STACK, self::BLOCKED_DEPRECATED], true),
        ));

        return array_map(function (SiteType $type) {
            $profile = $type->servingProfile();
            $blocked = $this->unavailable($type);
            $available = $blocked === null;

            return [
                'name' => $type->name(),
                'title' => app(SiteTypeText::class)->title($type->name()),
                'tagline' => app(SiteTypeText::class)->tagline($type->name()),
                'icon' => $type->icon(),
                'category' => $type->category(),
                'popular' => $type->popular(),
                // Internal: how we build it. The user is never asked to choose.
                'method' => $type->method(),
                'serving_profile' => $profile,
                'needs_database' => $type->needsDatabase(),
                // Which engines, not just whether, so the frontend need not infer the list.
                // Empty when the type needs no database.
                'accepted_engines' => $this->acceptedEngines($type),
                'available' => $available,
                'unavailable_reason' => $blocked['reason'] ?? null,
                // The same block as a stable value: 'runtime' | 'database' | 'php_version' |
                // 'web_server', null when available. `unavailable_reason` is the sentence to
                // show; this is the one to branch on.
                'unavailable_code' => $blocked['code'] ?? null,
                // The runtime that would have to be installed to make this card usable. Null when
                // available, or when nothing installable would fix it (e.g. a web-server refusal).
                'installable_runtime' => $blocked['runtime'] ?? null,
                'has_installer' => $this->installers->installerForType($type->name()) !== null,
                // So the version picker offers only what this application runs on.
                'node_version_range' => $type->supportedNodeRange(),
                'php_version_range' => $type->supportedPhpRange(),
                'fields' => [...$type->fields(), ...$this->engineField($type)],
                'recipe' => $type instanceof RecipeSiteType ? app(RecipePresenter::class)->summary($type->recipe()) : null,
            ];
        }, $types);
    }

    /**
     * Why this server cannot serve a site the way it is about to be built, or
     * null when it can.
     *
     * unavailable() answers for the type, and a git site's type is offered wherever any one of
     * its profiles is hosted. This is the second half: the profile this request resolves to.
     */
    public function unservedProfile(string $profile): ?string
    {
        if ($this->capabilities->hosts($profile)) {
            return null;
        }

        return __('application.unavailable.profile', [
            'profile' => match ($profile) {
                'php' => 'PHP',
                'node' => 'Node.js',
                default => $profile,
            },
        ]);
    }

    /**
     * Why this server cannot offer a site type, or null when it can.
     *
     * One method so the card grid and the create endpoint cannot disagree. Carries a stable `code`
     * as well as the translated sentence, for anything deciding what to *do* about the block.
     *
     * @return array{code: string, reason: string, runtime: ?string}|null
     */
    public function unavailable(SiteType $type): ?array
    {
        if ($type instanceof RecipeSiteType && $type->recipe()->deprecated) {
            return [
                'code' => self::BLOCKED_DEPRECATED,
                'reason' => __('application.unavailable.deprecated'),
                'runtime' => null,
            ];
        }

        // First, as the strongest refusal: a Docker box HAS php and node (the panel needs both),
        // so the runtime check below would pass for WordPress.
        // `servingProfiles()`, not `servingProfile()`: a type is blocked only when this server
        // hosts NONE of its profiles (the git card's declared `php` fallback would be refused on
        // every MERN server otherwise).
        $hosted = array_filter(
            $type->servingProfiles(),
            fn (string $profile): bool => $this->capabilities->hosts($profile),
        );

        if ($hosted === []) {
            return [
                'code' => self::BLOCKED_STACK,
                // "Containers only" is true of a Docker server and of nothing else.
                'reason' => $this->capabilities->hosts('docker')
                    ? __('application.unavailable.stack')
                    : __('application.unavailable.stack_profile'),
                // Nothing to install would change this, so the card must not
                // offer to fix itself.
                'runtime' => null,
            ];
        }

        $runtime = $this->requiredRuntime($type->servingProfile());

        if ($runtime !== null && ! $this->capabilities->supports($runtime)) {
            return [
                'code' => self::BLOCKED_RUNTIME,
                'reason' => __("application.unavailable.{$runtime}"),
                'runtime' => $runtime,
            ];
        }

        if (($blocked = $this->phpVersionBlock($type)) !== null) {
            return $blocked;
        }

        $missing = $this->missingEngines($type);

        if ($missing !== null && ($old = $this->engineTooOld($type)) !== null) {
            return [
                'code' => self::BLOCKED_DATABASE_VERSION,
                'reason' => __('application.unavailable.database_version', [
                    'type' => app(SiteTypeText::class)->title($type->name()),
                    ...$old,
                ]),
                'runtime' => null,
            ];
        }

        if ($missing !== null) {
            return [
                'code' => self::BLOCKED_DATABASE,
                'reason' => __('application.unavailable.database', ['engines' => $missing]),
                'runtime' => null,
            ];
        }

        $webServer = (string) $this->capabilities->webServer();
        $allowed = (array) config("server.web_server_drivers.{$webServer}.site_types", []);

        // An empty list means no restriction (nginx, Apache). A web server lists types only when
        // it supports some and not others.
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
     * Asked of every type that needs a database, not only the MySQL-less ones: an engine that is
     * up but can never serve this application (MongoDB for WordPress) must grey the card, not
     * fail at provisioning.
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
     * Empty for a type that needs no database and for one with no installer. Read from the
     * installer, which is what provisioning asks, so the catalog cannot advertise a pairing that
     * creating would refuse.
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
     * A database-engine picker, but only where there is genuinely a choice.
     *
     * Zero or one usable engine means no field. Two or more means the panel would otherwise choose
     * silently, and that choice is permanent. `mysql, mariadb` is not a choice: the two cannot
     * coexist, so exactly one is ever usable.
     *
     * Built here because only this class knows what the *server* has.
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
            // The first accepted engine this server has, which is what provisioning falls back to
            // when the field is absent.
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
     * The version belongs here rather than in {@see engineUsable()} because it depends on which
     * application is asking, which keeps the reachability answer memoized across every type.
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

    /**
     * The first engine this type accepts that is here and running but below
     * the type's minimum, or null when no engine falls short that way.
     *
     * Only asked once {@see missingEngines()} has found nothing usable, so it never greys an
     * available card.
     *
     * @return array{engine: string, minimum: string, version: string}|null
     */
    private function engineTooOld(SiteType $type): ?array
    {
        $installer = $this->installers->installerForType($type->name());

        if ($installer === null) {
            return null;
        }

        foreach ($this->acceptedEngines($type) as $engine) {
            if (! $this->engineUsable($engine)) {
                continue;
            }

            $minimum = $this->versions->shortfall($engine, $installer->minimumEngineVersions());

            if ($minimum !== null) {
                return [
                    'engine' => (string) config("server.databases.engines.{$engine}.label", $engine),
                    'minimum' => $minimum,
                    'version' => (string) $this->versions->version($engine),
                ];
            }
        }

        return null;
    }

    /**
     * Can this server actually serve an application from `$engine`?
     *
     * Memoized for the request: `available()` is a live `SELECT 1` through `sudo`, asked once per
     * type. It answers "did the query succeed", so an absent, stopped or not-permitted engine are
     * all `false` (a broken sudo grant greys the whole catalog).
     * `DatabaseManager::capabilities()` separates the three states.
     */
    private function engineUsable(string $engine): bool
    {
        if (! array_key_exists($engine, $this->usableEngines)) {
            $this->usableEngines[$engine] = in_array($engine, $this->databases->engineNames(), true)
                && $this->databases->engine($engine)->available();
        }

        return $this->usableEngines[$engine];
    }

    /**
     * A type with a PHP range, on a server with no installed version inside it.
     *
     * Without this, the card says "available" and the filled-in form is then refused with
     * "choose a version in the range" (e.g. PrestaShop 7.2 – 8.1 where the repo only has 8.2+).
     * The package index is read only when nothing installed fits, so the catalog does not pay an
     * `apt-cache` call per type.
     *
     * @return array{code: string, reason: string, runtime: ?string}|null
     */
    private function phpVersionBlock(SiteType $type): ?array
    {
        $range = $type->supportedPhpRange();

        if ($range === null) {
            return null;
        }

        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;
        $fits = fn (string $version): bool => SupportedPhpVersion::within($min, $max, $version);

        if (array_filter($this->phpVersions->versions(), $fits) !== []) {
            return null;
        }

        // Newest first, so the suggestion is the one with the longest life left.
        $installable = array_values(array_filter($this->php->installable(), $fits));
        $replace = [
            'type' => app(SiteTypeText::class)->title($type->name()),
            'range' => SupportedPhpVersion::describe($min, $max),
        ];

        return [
            'code' => self::BLOCKED_PHP_VERSION,
            'reason' => $installable === []
                ? __('application.unavailable.php_version_none', $replace)
                : __('application.unavailable.php_version_install', [...$replace, 'version' => $installable[0]]),
            // Not `php`: that would offer to install the runtime, which is
            // already here. The version is chosen on the PHP screen.
            'runtime' => null,
        ];
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
