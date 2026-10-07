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
use App\Rules\ContainerMountPath;
use App\Rules\SupportedNodeVersion;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
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

        // And this asks Docker — only when a volume needs a name made up.
        $takenVolumes = $this->takenVolumeNames($data);

        try {
            // The application and its primary hostname are one record from the
            // caller's point of view. If the hostname loses a uniqueness race,
            // rolling both back avoids an invisible half-created application
            // that then blocks the same name on retry.
            $application = DB::transaction(function () use ($data, $type, $servingProfile, $origin, $generatedUsername, $takenVolumes): Application {
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

                // Derived here, not accepted from the client: it names the
                // web-server config file, and a caller choosing that is a caller
                // choosing which file the panel overwrites.
                $slug = Application::uniqueSlug((string) $data['name']);

                $application = Application::forceCreate([
                    'slug' => $slug,
                    'site_type' => $type->name(),
                    // Derived, never taken from the client — from the rendering type
                    // the user chose, or the site type where there is none. See the
                    // resolver for why the two must not be decided separately.
                    'serving_profile' => $servingProfile,
                    'rendering_type' => $data['rendering_type'] ?? null,
                    'status' => ApplicationStatus::Pending,
                    'system_user_id' => $data['system_user_id'],
                    // A type's declared fields that are real columns.
                    //
                    // `typeSettings()` deliberately skips those — a column
                    // should be a column, not a key in a JSON blob — but the
                    // list below sets only the fields every type shares, so a
                    // column-backed type field was dropped by both and
                    // vanished. Measured, not theorised: a Docker application
                    // was created with `image`, `container_port` and `compose`
                    // all null, and the user's pasted compose file simply did
                    // not exist afterwards.
                    //
                    // Spread FIRST, so the explicit entries below win. Last
                    // was my first attempt and it is exactly backwards: a
                    // type declaring `deploy_script` then overwrote the
                    // CRLF-normalised value with the raw one, and a script
                    // pasted from Windows went back to failing with
                    // "command not found: composer\r". The suite caught it.
                    ...$this->typeColumns($type->fields(), $data),
                    // The create form's "make a new one" fields, folded into the
                    // columns the compose file is rendered from.
                    //
                    // Resolved here rather than left as separate columns, so
                    // there is ONE place that answers "what network is this site
                    // on" — a `docker_network_new` living alongside
                    // `docker_network` would be a second source for the same
                    // fact, and the compose renderer would have to know about
                    // both. The objects themselves are created on the box at
                    // provision time; this records the intent.
                    ...$this->containerWiring($data, $slug, $takenVolumes),
                    // Where the container sees the site's directory. Stored
                    // rather than left null, because null is the `/app` every
                    // older site was given — and `/app` is where a quarter of
                    // popular images keep their program. Only the Docker type:
                    // a one-click app ships its own compose file, which has no
                    // site mount at all.
                    'site_mount_path' => $type->name() === 'docker' ? ContainerMountPath::SITE_MOUNT : null,
                    'name' => $data['name'],
                    'domain' => $data['domain'],
                    // A blank version is the server default, resolved and
                    // stored now. Left blank it was resolved again at every
                    // render: the API had no version to show, and a change to
                    // the default moved the site to another PHP unasked.
                    'php_version' => $servingProfile === 'php'
                        ? (($data['php_version'] ?? null) ?: app(ServerDefaultPhp::class)->version())
                        : ($data['php_version'] ?? null),
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
                    ...$this->splitSecrets($this->typeSettings($type->fields(), $data), $this->containerEnv($data)),
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
    private function splitSecrets(array $settings, array $containerEnv = []): array
    {
        $secrets = array_intersect_key($settings, array_flip(Application::INSTALL_SECRET_KEYS));
        $settings = array_diff_key($settings, $secrets);

        // A container's env vars travel the same way: encrypted, never
        // serialised, and gone once provisioning has written them to the
        // site's env file. See ApplicationProvisioner::writeContainerEnv().
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
     * Docker volume names already on the box, when a mount needs a name made
     * up — and only then, because asking is a shell-out.
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
     * declared — so an unexpected key in the payload cannot end up stored.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * The type's declared fields that are columns on the model.
     *
     * The mirror of {@see typeSettings()}: that one takes the fields which are
     * NOT columns, this one takes the fields which are. Between them every
     * declared field lands somewhere, which is the property that was missing —
     * a field in neither list is accepted by validation and then silently
     * discarded.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * The network and volume a container site was created with.
     *
     * `docker_network_new` and `docker_network` are two answers to one question,
     * and validation refuses both at once (`prohibits`) — so whichever arrived
     * lands in the same column and nothing downstream has to ask which field it
     * came from.
     *
     * The volume pair becomes the first entry of `volume_mounts`, the same shape
     * the Container card edits. Both halves or neither: validation makes each
     * `required_with` the other, so a half-filled pair cannot reach here.
     *
     * **`docker_mode` is not resolved here and not stored at all** — see the
     * form-only list in `typeSettings()`. It answers a question about the form, and
     * the site already records which way it was made by having a compose file or not.
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

        // Any number more (DS-03), named or not. Every typed name is reserved
        // before any is made up, so a made-up name never takes one the user
        // typed further down the list. `sortKeys()`: validated() rebuilds the
        // list rule by rule, so a row with a `volume` comes back ahead of rows
        // without one.
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
     * `<site>-<last path segment>`, made unique against what the box and this
     * request already use: `memos-opt-memos` for /var/opt/memos would be
     * clearer, but the spec asks for the short form and a suffix settles the
     * rare clash (`jellyfin-config`, `jellyfin-config-2`).
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

        /*
         * Declared fields that shape the create FORM and are never stored anywhere.
         *
         * `docker_mode` chooses whether the Docker card asks for an image and a port
         * or for a compose file. It answers a question about the form, not about the
         * site — and the site already records which way it was made, by having a
         * compose file or not. Storing it would be a second source for that, free to
         * drift, and the first person to trust the wrong one gets a surprise.
         *
         * Listed rather than silently dropped: the guard in ContainerSupervisorTest
         * requires every declared field to be a column, a setting, or named as a
         * deliberate exception, and this is where the exception lives.
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
        // Both need one, for the same reason: nginx proxies to a loopback port
        // and something has to be listening on it. A container without an
        // allocated port renders a vhost pointing at `127.0.0.1:` and every
        // request to the site is a 502.
        return in_array($servingProfile, ['node', 'docker'], true)
            ? $this->ports->allocate()
            : null;
    }
}
