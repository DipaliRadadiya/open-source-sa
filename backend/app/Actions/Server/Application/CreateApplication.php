<?php

namespace App\Actions\Server\Application;

use App\Contracts\SiteType;
use App\Enums\ApplicationStatus;
use App\Enums\DomainOrigin;
use App\Enums\DomainType;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\ApplicationRun;
use App\Models\SystemUser;
use App\Rules\ContainerMountPath;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Php\ServerDefaultPhp;
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
 * Nothing is written to the server here: the app is saved as `pending` and
 * provisioning moves it on to `active`.
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

        // Only the *name* is settled here (choosing it reads the server via `getent`, which must
        // not happen inside a transaction). Nothing is written to /etc/passwd: server writes
        // belong to provisioning. Uses the name the create form chose, which the request has
        // already checked is free.
        $generatedUsername = ($data['generate_system_user'] ?? false)
            ? (($data['system_user']['username'] ?? null) ?: $this->usernames->forApplication((string) $data['name']))
            : null;

        // Before the transaction: it asks fnm.
        $data['node_version'] = $this->nodeVersion($data, $type, $servingProfile);

        // Also before it: this asks Docker, and only when a volume needs a name made up.
        $takenVolumes = $this->takenVolumeNames($data);

        try {
            // The application and its primary hostname are one record: if the hostname loses a
            // uniqueness race, both roll back, so no half-created application blocks a retry.
            $application = DB::transaction(function () use ($data, $type, $servingProfile, $origin, $generatedUsername, $takenVolumes): Application {
                // Same transaction as the application, so a failure rolls the account row back too.
                if ($generatedUsername !== null) {
                    $data['system_user_id'] = SystemUser::create([
                        'username' => $generatedUsername,
                        'home_path' => rtrim((string) config('server.home_base'), '/').'/'.$generatedUsername,
                        'shell' => '/bin/bash',
                        'sudo' => false,
                        'ssh_access' => false,
                        // Set on the account when provisioning creates it
                        // (CreateSystemUser::ensureOnServer()).
                        'password' => ($data['system_user']['password'] ?? null) ?: null,
                    ])->id;
                }

                // Never accepted from the client: it names the web-server config file the panel
                // overwrites.
                $slug = Application::uniqueSlug((string) $data['name']);

                // Allocated when the app needs a process and the user did not pick a port.
                $appPort = $this->port($data, $servingProfile);

                $application = Application::forceCreate([
                    'slug' => $slug,
                    'site_type' => $type->name(),
                    // Derived, never taken from the client. See ServingProfile.
                    'serving_profile' => $servingProfile,
                    'rendering_type' => $data['rendering_type'] ?? null,
                    'status' => ApplicationStatus::Pending,
                    'system_user_id' => $data['system_user_id'],
                    // A type's declared fields that are real columns (typeSettings() skips those).
                    // Spread FIRST so the explicit entries below win: otherwise a type declaring
                    // `deploy_script` overwrites the CRLF-normalised value with the raw one.
                    ...$this->typeColumns($type->fields(), $data),
                    // The create form's "make a new one" fields, folded into the columns the
                    // compose file is rendered from. Objects are created at provision time.
                    ...$this->containerWiring($data, $slug, $takenVolumes),
                    // Where the container sees the site's directory. Stored, not null: null means
                    // the legacy `/app`. Docker type only; a one-click app ships its own compose file.
                    'site_mount_path' => $type->name() === 'docker' ? ContainerMountPath::SITE_MOUNT : null,
                    'name' => $data['name'],
                    'domain' => $data['domain'],
                    // A blank version is the server default, resolved and stored now so a later
                    // change to the default does not move the site to another PHP.
                    'php_version' => $servingProfile === 'php'
                        ? (($data['php_version'] ?? null) ?: app(ServerDefaultPhp::class)->version())
                        : ($data['php_version'] ?? null),
                    'node_version' => $data['node_version'] ?? null,
                    'app_port' => $appPort,
                    // A second port for a process that binds one more (n8n's task broker).
                    'internal_port' => $type?->needsInternalPort() ? $this->ports->allocate(array_filter([$appPort])) : null,
                    // The type's own default, not a bare '/'.
                    'web_root' => $data['web_root'] ?? $type?->defaultWebRoot() ?? '/',
                    'build_command' => $data['build_command'] ?? null,
                    // Normalised like UpdateDeploySettingsRequest: `sh` reads a pasted Windows \r as
                    // part of the command ("command not found: composer\r").
                    'deploy_script' => isset($data['deploy_script'])
                        ? str_replace("\r\n", "\n", (string) $data['deploy_script'])
                        : null,
                    'start_command' => $data['start_command'] ?? null,
                    'git_account_id' => $data['git_account_id'] ?? null,
                    'repository' => $data['repository'] ?? null,
                    'repository_url' => $data['repository_url'] ?? null,
                    'branch' => $data['branch'] ?? null,
                    ...$this->splitSecrets($this->typeSettings($type->fields(), $data), $this->containerEnv($data)),
                ]);

                // The domains table feeds the Domains screen; `applications.domain` mirrors the
                // primary row created here.
                $application->domains()->create([
                    'domain' => strtolower(trim((string) $application->domain)),
                    'type' => DomainType::Primary,
                    // Not only the client's word: a wildcard-DNS name mislabelled as the user's own
                    // would be sent to Let's Encrypt and spend its shared weekly limit.
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
            // Another request can claim the same name or domain between validation and the insert.
            // Re-run the unique rules so the client gets a localized 422, not a DB error.
            Validator::make($data, [
                'name' => [Rule::unique('applications', 'name')],
                'domain' => [Rule::unique('application_domains', 'domain')],
            ])->validate();

            // A different unique index (slug, port, webhook identifier) failed: not a validation problem.
            throw $exception;
        }

        // The request must not wait for provisioning; the client polls the status, and
        // Background jobs reads the install run opened here.
        app(ApplicationRuns::class)->open($application, ApplicationRun::INSTALL, Auth::id());

        ProvisionApplication::queueOrFail($application->id, Auth::id());

        return $application->fresh(['systemUser']);
    }

    /**
     * Separate the installer's passwords from the rest of the answers.
     *
     * `settings` is plain JSON the API returns; passwords go to `install_secrets`, which is
     * encrypted, never serialized, and cleared once the install succeeds.
     *
     * @param  array<string, mixed>  $settings
     * @return array{settings: array<string, mixed>, install_secrets: array<string, mixed>|null}
     */
    private function splitSecrets(array $settings, array $containerEnv = []): array
    {
        $secrets = array_intersect_key($settings, array_flip(Application::INSTALL_SECRET_KEYS));
        $settings = array_diff_key($settings, $secrets);

        // A container's env vars travel the same way (see ApplicationProvisioner::writeContainerEnv()).
        if ($containerEnv !== []) {
            $secrets[Application::CONTAINER_ENV_SECRET] = $containerEnv;
        }

        return [
            'settings' => $settings,
            'install_secrets' => $secrets === [] ? null : $secrets,
        ];
    }

    /**
     * The env vars a container site was created with, as `[key, value]` pairs.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string}>
     */
    private function containerEnv(array $data): array
    {
        $pairs = [];

        $rows = (array) ($data['env'] ?? []);
        ksort($rows);

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');

            if ($key !== '') {
                $pairs[] = [$key, (string) ($row['value'] ?? '')];
            }
        }

        return $pairs;
    }

    /**
     * Docker volume names already on the box; only asked when a mount needs a name made up.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function takenVolumeNames(array $data): array
    {
        $unnamed = collect((array) ($data['volume_mounts'] ?? []))
            ->contains(fn ($mount): bool => is_array($mount) && blank($mount['volume'] ?? null));

        if (! $unnamed) {
            return [];
        }

        return collect(app(DockerResources::class)->volumes())->pluck('name')->map(fn ($name): string => (string) $name)->all();
    }

    /**
     * The type-specific answers, taken strictly from the fields the site type
     * declared, so an unexpected payload key cannot be stored.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * The type's declared fields that are columns on the model.
     *
     * The mirror of {@see typeSettings()} (the fields which are NOT columns): between them
     * every declared field lands somewhere instead of being silently discarded.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * The network and volume a container site was created with.
     *
     * `docker_network_new` and `docker_network` are refused together by validation, so
     * whichever arrived lands in the same column. The volume pair becomes the first entry of
     * `volume_mounts` (both halves or neither, enforced by `required_with`). `docker_mode`
     * is form-only and not stored; see `typeSettings()`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function containerWiring(array $data, string $slug, array $takenVolumes): array
    {
        $wiring = [];

        $network = trim((string) ($data['docker_network_new'] ?? '')) !== ''
            ? (string) $data['docker_network_new']
            : (string) ($data['docker_network'] ?? '');

        if ($network !== '') {
            $wiring['docker_network'] = $network;
        }

        $mounts = [];
        $volume = trim((string) ($data['volume_new'] ?? ''));
        $path = trim((string) ($data['volume_path'] ?? ''));

        if ($volume !== '' && $path !== '') {
            $mounts[] = ['volume' => $volume, 'path' => $path];
        }

        // Any number more, named or not. Typed names are reserved before any is made up. `sortKeys()`:
        // validated() rebuilds the list rule by rule, so rows with a `volume` come back first.
        $rows = collect((array) ($data['volume_mounts'] ?? []))
            ->sortKeys()
            ->filter(fn ($mount): bool => is_array($mount) && trim((string) ($mount['path'] ?? '')) !== '')
            ->map(fn (array $mount): array => [
                'volume' => trim((string) ($mount['volume'] ?? '')),
                'path' => rtrim(trim((string) $mount['path']), '/'),
            ])
            ->values();

        $taken = array_merge($takenVolumes, array_column($mounts, 'volume'), $rows->pluck('volume')->filter()->all());

        foreach ($rows as $row) {
            if ($row['volume'] === '') {
                $row['volume'] = $this->volumeName($slug, $row['path'], $taken);
                $taken[] = $row['volume'];
            }

            $mounts[] = $row;
        }

        if ($mounts !== []) {
            $wiring['volume_mounts'] = $mounts;
        }

        return $wiring;
    }

    /**
     * `<site>-<last path segment>`, made unique against what the box and this request already
     * use (`jellyfin-config`, `jellyfin-config-2`).
     *
     * @param  list<string>  $taken
     */
    private function volumeName(string $slug, string $path, array $taken): string
    {
        $segment = (string) preg_replace('/[^a-zA-Z0-9_.-]+/', '-', basename($path));
        $base = trim(substr(trim($slug.'-'.trim($segment, '-.'), '-'), 0, 120), '-.');
        $base = DockerResources::validName($base) ? $base : 'site-'.substr(sha1($slug.$path), 0, 8);

        $name = $base;

        for ($suffix = 2; in_array($name, $taken, true); $suffix++) {
            $name = $base.'-'.$suffix;
        }

        return $name;
    }

    private function typeColumns(array $fields, array $data): array
    {
        $columns = (new Application)->getFillable();
        $values = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];

            if (in_array($name, $columns, true) && array_key_exists($name, $data)) {
                $values[$name] = $data[$name];
            }
        }

        return $values;
    }

    private function typeSettings(array $fields, array $data): array
    {
        $columns = (new Application)->getFillable();
        $settings = [];

        // Which database engine to provision, where the user was offered a choice. Not one of the
        // type's declared fields (the catalog adds it), so the loop below would drop it. A setting,
        // not a column: it is the request; the `databases` row records what was provisioned.
        // Absent means "decide at provisioning time".
        if (isset($data['database_engine'])) {
            $settings['database_engine'] = (string) $data['database_engine'];
        }

        /*
         * Declared fields that shape the create FORM and are never stored.
         *
         * `docker_mode` picks image+port or compose file; the site already records which by
         * having a compose file or not. ContainerSupervisorTest requires every declared field to
         * be a column, a setting, or a named exception, and this is where the exception lives.
         */
        $formOnly = ['docker_mode'];

        foreach ($fields as $field) {
            $name = (string) $field['name'];

            if (in_array($name, $formOnly, true)) {
                continue;
            }

            if (! in_array($name, $columns, true) && array_key_exists($name, $data)) {
                $settings[$name] = $data[$name];
            }
        }

        return $settings;
    }

    /**
     * The Node a site served by Node runs on: the one asked for, or one chosen now and recorded.
     *
     * Blank would give the installer a PATH with no Node (`npm: No such file or directory`).
     * Recording it also keeps the site on that Node when the server default changes later
     * (see {@see NodeRuntime::setDefault()}).
     *
     * The server default when it fits the type's range, else the newest installed version
     * that does ({@see NodeRuntime::bestFit()}). Null when nothing fits; the form refuses that.
     *
     * @param  array<string, mixed>  $data
     */
    private function nodeVersion(array $data, ?SiteType $type, string $servingProfile): ?string
    {
        if (filled($data['node_version'] ?? null) || $servingProfile !== 'node') {
            return $data['node_version'] ?? null;
        }

        $range = $type?->supportedNodeRange() ?? [];

        return $this->node->bestFit($range['min'] ?? null, $range['max'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function port(array $data, string $servingProfile): ?int
    {
        if (filled($data['app_port'] ?? null)) {
            return (int) $data['app_port'];
        }

        // Only apps that run a process need a port (PHP sites would exhaust the range). Keyed on the
        // serving profile, not the start command: a one-click Node app's installer writes its own.
        // Node and Docker both need one: nginx proxies to a loopback port, and without it the
        // vhost points at `127.0.0.1:` and every request is a 502.
        return in_array($servingProfile, ['node', 'docker'], true)
            ? $this->ports->allocate()
            : null;
    }
}
