<?php

namespace App\Services\Applications\Types;

use App\Models\Application;
use App\Rules\ContainerMemoryLimit;
use App\Rules\WithinHostCpus;
use App\Services\Server\HostCpus;

/**
 * A one-click application that runs as containers.
 *
 * The shared behavior of recipe-backed Docker app cards. `RecipeSiteType` supplies each app's
 * port, volumes and generated secrets from its recipe (`resources/recipes`: validated JSON
 * metadata and a closed compose template language). Rendering stays in `RecipeRenderer`, with
 * exceptional first-run logic behind PHP recipe hooks.
 *
 * `method() === 'one_click'` and `servingProfile() === 'docker'`: the profile is what the stack
 * gate reads (these show on a Docker box and are filtered off a LEMP one); the method drives
 * which screens the site gets.
 *
 * **No fields:** the app has already answered image and port, and a field offering to change
 * them would break the compose file the panel is about to write.
 */
abstract class AbstractDockerAppType extends AbstractSiteType
{
    /**
     * The port INSIDE the app's own container, which nginx is proxied to.
     *
     * Declared even though `ComposeValidator::publishedPort()` can find it in the rendered file,
     * because the template needs it to write the port mapping, and the two must match.
     */
    abstract public function containerPort(): int;

    /**
     * The volumes this app keeps data in, as role => path inside the container.
     *
     * Roles, not names: the volume name is `sv-app-<id>_<role>`, decided at install time because
     * it needs the id, so a template never contains a site-specific string.
     *
     * @return array<string, string>
     */
    abstract public function volumeRoles(): array;

    /**
     * Environment keys that must be generated per site, never defaulted.
     *
     * A shipped default secret would be shared by every install of that app on every server.
     * The installer generates one value per key per site.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return [];
    }

    /**
     * Generated secrets that must satisfy a password policy, not just be random.
     *
     * The default generator is `Str::random(32)` (alphanumeric), which an app demanding a symbol
     * refuses silently: Chatwoot creates no user and its onboarding answers the same redirect, so
     * the site never gets an owner.
     *
     * @return list<string>
     */
    public function complexSecrets(): array
    {
        return [];
    }

    /**
     * Generated secrets the compose file does NOT contain, and must not.
     *
     * Every other generated secret is read by the app from its environment (a stored secret the
     * template never interpolates is dead weight, an invariant the suite enforces). Chatwoot's
     * `ADMIN_PASSWORD` is the exception: the panel posts it to the first-run endpoint and shows
     * it once. Putting it in the compose file would expose the administrator's password to the
     * File Manager for no reader.
     *
     * @return list<string>
     */
    public function panelOnlySecrets(): array
    {
        return [];
    }

    /**
     * A first-run setup endpoint to claim, before a stranger does.
     *
     * Null for every app whose administrator is created during install (the compose file passes
     * the credential as an environment variable). Chatwoot's seeds create no user: they open a
     * setup endpoint to whoever asks first, on a public URL. The installer posts to it as soon as
     * the site is up, which creates the administrator and closes the endpoint.
     *
     * Returns the path and form fields; the HTTP and retrying stay in the installer.
     *
     * @return array{path: string, fields: array<string, string>}|null
     */
    public function firstRunClaim(Application $application): ?array
    {
        return null;
    }

    /**
     * Files this app cannot start without, as path-inside-the-container => body.
     *
     * For an app that exits on boot without a config file (Glance), since a named volume starts
     * empty. These become a **bind mount inside the site's own directory**, not a named volume:
     * `ComposeValidator` already permits a bind inside the site root, and the File Manager becomes
     * the editor. The container path's parent directory is what gets mounted, so two files in one
     * directory mount once.
     *
     * @return array<string, string>
     */
    public function starterFiles(): array
    {
        return [];
    }

    /**
     * The memory ceiling this app needs, or null to take the server default.
     *
     * The panel's generic default (512m) suits a small container but breaks a bigger one as a
     * 502 that mentions nothing about memory: a JVM sizes its heap at a quarter of the container
     * limit (Metabase got ~124MB against a 1GB minimum), and NocoDB aborts with a core dump.
     * Ghost and Wiki.js run inside 512m, so this is per-app.
     *
     * A user's own `memory_limit` on the application still wins: this is the floor the app needs
     * to start, not a policy.
     */
    public function defaultMemoryLimit(): ?string
    {
        return null;
    }

    /**
     * The environment key this app is told its own URL through, or null.
     *
     * Null is a real answer: Metabase and Wiki.js keep their URL in their own database, so
     * passing one would be a second source. Declared here, not inferred from the template:
     * `syncUrl()` has nothing to reconcile when it is null.
     */
    public function urlEnvKey(): ?string
    {
        return null;
    }

    /**
     * No Clone: it copies served files, and a container's state is in its
     * volumes, so a clone would produce a site pointing at the original's data.
     *
     * **Backup is kept** (as in {@see DockerSiteType}): `ArchiveVolumes` captures the volumes and
     * `ArchiveFiles` the compose file beside them, which together are the whole of a container site.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), ['app_clone']));
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'docker';
    }

    /**
     * A container brings its own database, which is the premise of the stack —
     * it is why a Docker server manages no engine.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * How big to run it, and nothing else.
     *
     * The app decides its image, port and volumes. Size is a fact about this server and what
     * else is on it, so only the person deploying can answer it (otherwise a one-click installs
     * at the app's floor and must be resized on a second screen).
     *
     * The help text names the declared floor, not the server default: telling someone installing
     * Metabase that an empty field means 512m would be wrong, and the app would fail to start.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $floor = $this->defaultMemoryLimit();
        $effective = $floor ?? (string) config('server.docker.default_memory_limit', '512m');

        return [
            $this->field('memory_limit', 'text', extra: [
                'placeholder' => $effective,
                // Two help texts: an app with a declared floor needs that figure, and lowering it
                // overrides a measurement (the app then 502s with nothing about memory shown).
                'help' => __(
                    $floor !== null
                        ? 'application.help.memory_limit_app_floor'
                        : 'application.help.memory_limit_app',
                    ['default' => $effective],
                ),
            ]),

            $this->field('cpu_limit', 'text', extra: [
                'placeholder' => __('application.placeholders.cpu_limit'),
                'help' => __('application.help.cpu_limit_app', [
                    'cores' => app(HostCpus::class)->count(),
                ]),
            ]),
        ];
    }

    /**
     * The same two rules the Docker card and the Container screen use, so a size accepted by one
     * form is never refused by another. The CPU bound must be here, or an over-provisioned
     * one-click installs and then fails at `compose up`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'memory_limit' => ['nullable', 'string', 'max:20', new ContainerMemoryLimit],
            'cpu_limit' => ['nullable', 'string', 'max:16', new WithinHostCpus],
        ];
    }
}
