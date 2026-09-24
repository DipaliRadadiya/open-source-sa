<?php

namespace App\Actions\Server\Application;

use App\Contracts\SiteType;
use App\Enums\ApplicationStatus;
use App\Enums\DomainOrigin;
use App\Enums\DomainType;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\SystemUser;
use App\Rules\SupportedNodeVersion;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\SystemUsers\SystemUsernameGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Record an application the user asked for.
 *
 * P1 stops here deliberately: nothing is written to the server, so the app is
 * saved as `pending` and the UI must say "not deployed yet". Provisioning
 * arrives in P2 and will move it through `provisioning` to `active`.
 */
class CreateApplication
{
    public function __construct(
        private SiteTypeManager $siteTypes,
        private ActivityLogger $activityLogger,
        private PortAllocator $ports,
        private SystemUsernameGenerator $usernames,
        private NodeRuntime $node,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Application
    {
        $type = $this->siteTypes->find((string) $data['site_type']);
        $servingProfile = ServingProfile::resolve($type, $data);
        $origin = DomainOrigin::tryFrom((string) ($data['domain_type'] ?? '')) ?? DomainOrigin::Custom;

        // Only the *name* is settled here, and only because choosing it reads
        // the server (`getent`) — which must not happen inside a transaction.
        // Nothing is written to /etc/passwd: this action records what the user
        // asked for, and every server write belongs to provisioning. Creating
        // the account here is what the first version of this feature did, and
        // it put a useradd in the one place the class docblock promises there
        // is none.
        $generatedUsername = ($data['generate_system_user'] ?? false)
            ? $this->usernames->forApplication((string) $data['name'])
            : null;

        // Before the transaction for the same reason: it asks fnm.
        $data['node_version'] = $this->nodeVersion($data, $type, $servingProfile);

        try {
            // The application and its primary hostname are one record from the
            // caller's point of view. If the hostname loses a uniqueness race,
            // rolling both back avoids an invisible half-created application
            // that then blocks the same name on retry.
            $application = DB::transaction(function () use ($data, $type, $servingProfile, $origin, $generatedUsername): Application {
                // In the transaction with the application, so a site and its
                // owner are one fact rather than two. If anything below fails,
                // the rollback takes the account row with it and there is no
                // orphan to tidy up — which is exactly the cleanup code this
                // replaces.
                if ($generatedUsername !== null) {
                    $data['system_user_id'] = SystemUser::create([
                        'username' => $generatedUsername,
                        'home_path' => rtrim((string) config('server.home_base'), '/').'/'.$generatedUsername,
                        'shell' => '/bin/bash',
                        'sudo' => false,
                        'ssh_access' => false,
                    ])->id;
                }

                $application = Application::forceCreate([
                    // Derived here, not accepted from the client: it names the
                    // web-server config file, and a caller choosing that is a caller
                    // choosing which file the panel overwrites.
                    'slug' => Application::uniqueSlug((string) $data['name']),
                    'site_type' => $type->name(),
                    // Derived, never taken from the client — from the rendering type
                    // the user chose, or the site type where there is none. See the
                    // resolver for why the two must not be decided separately.
                    'serving_profile' => $servingProfile,
                    'rendering_type' => $data['rendering_type'] ?? null,
                    'status' => ApplicationStatus::Pending,
                    'system_user_id' => $data['system_user_id'],
                    'name' => $data['name'],
                    'domain' => $data['domain'],
                    'php_version' => $data['php_version'] ?? null,
                    'node_version' => $data['node_version'] ?? null,
                    // Allocated when the app needs a process and the user did not pick
                    // one. A port the panel chose is checked against both the database
                    // and what is actually listening; a port the user typed is checked
                    // the same way at validation.
                    'app_port' => $this->port($data, $servingProfile),
                    // The type's own default, not a bare '/': a framework
                    // application served from its root publishes its own source.
                    'web_root' => $data['web_root'] ?? $type?->defaultWebRoot() ?? '/',
                    'build_command' => $data['build_command'] ?? null,
                    // Normalised the same way UpdateDeploySettingsRequest does: a
                    // script pasted from Windows carries \r, and `sh` reads it as part
                    // of the command — "command not found: composer\r" is impossible
                    // to see in a log.
                    'deploy_script' => isset($data['deploy_script'])
                        ? str_replace("\r\n", "\n", (string) $data['deploy_script'])
                        : null,
                    'start_command' => $data['start_command'] ?? null,
                    'git_account_id' => $data['git_account_id'] ?? null,
                    'repository' => $data['repository'] ?? null,
                    'repository_url' => $data['repository_url'] ?? null,
                    'branch' => $data['branch'] ?? null,
                    ...$this->splitSecrets($this->typeSettings($type->fields(), $data)),
                ]);

                // The domains table is the list the Domains screen reads, and until now
                // nothing wrote to it at create time — only the migration that
                // introduced the table backfilled the sites that existed then. So every
                // application made since came up with an empty Domains section while
                // plainly answering on a domain.
                //
                // `applications.domain` stays the mirror of whichever row is primary;
                // this is the row it mirrors.
                $application->domains()->create([
                    'domain' => strtolower(trim((string) $application->domain)),
                    'type' => DomainType::Primary,
                    // Trusted from the client, but not only: a wildcard-DNS name
                    // mislabelled as the user's own would be sent to Let's Encrypt and
                    // spend from a weekly limit shared with the whole internet.
                    'is_test' => $origin->isTemporary()
                        || ApplicationDomain::looksTemporary((string) $application->domain),
                ]);

                $this->activityLogger->log('application.created', $application, [
                    'name' => $application->name,
                    'site_type' => $application->site_type,
                ]);

                return $application;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Validation runs before this transaction, but another request can
            // claim the same name or domain between that check and the insert.
            // Re-run the two user-owned unique rules after rollback so Laravel
            // returns its normal localized 422 instead of leaking a DB error.
            Validator::make($data, [
                'name' => [Rule::unique('applications', 'name')],
                'domain' => [Rule::unique('application_domains', 'domain')],
            ])->validate();

            // A different unique index (slug, port, webhook identifier) failed;
            // do not mislabel it as a name/domain validation problem.
            throw $exception;
        }

        // Provisioning is long enough that the request must not wait for it;
        // the client polls the application's status.
        ProvisionApplication::dispatch($application->id, Auth::id());

        return $application->fresh(['systemUser']);
    }

    /**
     * Separate the installer's passwords from the rest of the answers.
     *
     * `settings` is plain JSON and the API returns it; the passwords go to
     * `install_secrets`, which is encrypted, never serialized, and cleared once
     * the install succeeds.
     *
     * @param  array<string, mixed>  $settings
     * @return array{settings: array<string, mixed>, install_secrets: array<string, mixed>|null}
     */
    private function splitSecrets(array $settings): array
    {
        $secrets = array_intersect_key($settings, array_flip(Application::INSTALL_SECRET_KEYS));

        return [
            'settings' => array_diff_key($settings, $secrets),
            'install_secrets' => $secrets === [] ? null : $secrets,
        ];
    }

    /**
     * The type-specific answers, taken strictly from the fields the site type
     * declared — so an unexpected key in the payload cannot end up stored.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function typeSettings(array $fields, array $data): array
    {
        $columns = (new Application)->getFillable();
        $settings = [];

        // Which database engine to provision, where the user was offered a
        // choice. Carried here rather than picked up by the loop below, which
        // reads the *type's* declared fields — and this is not one of them:
        // the catalog adds it only on a server that has two of the engines the
        // application accepts, because only the catalog knows what the server
        // has. Left to the loop it would be dropped silently.
        //
        // A setting rather than a column because it is the *request*, not the
        // record: what was actually provisioned is on the `databases` row,
        // which carries its own `engine` and points at this application.
        // Absent means "decide at provisioning time" — what every existing
        // client already gets.
        if (isset($data['database_engine'])) {
            $settings['database_engine'] = (string) $data['database_engine'];
        }

        foreach ($fields as $field) {
            $name = (string) $field['name'];

            if (! in_array($name, $columns, true) && array_key_exists($name, $data)) {
                $settings[$name] = $data[$name];
            }
        }

        return $settings;
    }

    /**
     * The Node a site served by Node runs on — the one asked for, or, when
     * none was, one chosen now and recorded on the site.
     *
     * Leaving it blank handed the installer a PATH with no Node at all:
     * the installer resolves Node through the site's pinned version, and on a
     * server where nothing had linked a system-wide `node` into
     * /usr/local/bin, a Node-RED created without one died on
     * `npm: No such file or directory` (reproduced 2026-09-23). Recording it
     * also keeps the site where it was installed: changing the server default
     * later must not move a running site to another Node, which is what
     * {@see NodeRuntime::setDefault()} already promises for pinned sites.
     *
     * The server default when it fits the type's own range, else the newest
     * installed version that does. Null when nothing fits — validation has
     * already accepted the request, and the installer reports that case
     * rather than this guessing past it.
     *
     * @param  array<string, mixed>  $data
     */
    private function nodeVersion(array $data, ?SiteType $type, string $servingProfile): ?string
    {
        if (filled($data['node_version'] ?? null) || $servingProfile !== 'node') {
            return $data['node_version'] ?? null;
        }

        $range = $type?->supportedNodeRange() ?? [];
        $fits = fn (?string $version): bool => $version !== null
            && SupportedNodeVersion::admits($range['min'] ?? null, $range['max'] ?? null, $version);

        $default = $this->node->default();

        if ($fits($default)) {
            return $default;
        }

        return collect($this->node->versions())
            ->pluck('version')
            ->sort(fn (string $a, string $b) => version_compare($b, $a))
            ->first($fits);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function port(array $data, string $servingProfile): ?int
    {
        if (filled($data['app_port'] ?? null)) {
            return (int) $data['app_port'];
        }

        // Only an application that runs something needs a port. Handing one to
        // every PHP site would exhaust the range for no reason.
        //
        // Keyed on the serving profile rather than on the start command: a
        // one-click Node application is never sent one — its installer writes
        // it — so asking for the command here left Uptime Kuma and friends
        // with no port at all, a unit with no `PORT`, and a reverse proxy
        // pointed at nothing.
        return $servingProfile === 'node' ? $this->ports->allocate() : null;
    }
}
