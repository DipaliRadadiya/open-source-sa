<?php

namespace App\Services\Applications\Types;

use App\Models\Application;
use App\Rules\ContainerMemoryLimit;
use App\Rules\WithinHostCpus;
use App\Services\Server\HostCpus;

/**
 * A one-click application that runs as containers.
 *
 * The shared half of every Docker app card. A subclass says what the app IS —
 * its compose template, the port to proxy, the volumes it keeps data in, the
 * secrets it needs generating — and nothing about how any of that is applied.
 *
 * **Why these are site types and not a catalogue file.** They sit beside the
 * sixteen host-installed one-click types and are read by the same catalog, the
 * same stack gate and the same create form. A YAML list would be quicker to
 * extend and would need its own loader, its own validation and its own answer
 * to every question `AbstractSiteType` already answers — and the first time one
 * app needed a rule the others did not, it would grow a scripting language.
 *
 * **Why `method() === 'one_click'` and `servingProfile() === 'docker'`.** The
 * profile is what the stack gate reads, so these appear on a Docker box and are
 * filtered off a LEMP one with no extra code — the mirror of how the PHP
 * one-clicks are filtered off a Docker box. The method drives which screens the
 * site gets.
 *
 * **No fields.** `DockerSiteType` asks for an image and a port because the user
 * is choosing what to run. Here the app has already answered both, and a field
 * offering to change them would be offering to break the compose file the panel
 * is about to write. The domain and the system user are all these need.
 */
abstract class AbstractDockerAppType extends AbstractSiteType
{
    /**
     * The blade template that renders this app's compose file.
     *
     * Defaults to the shared single-container template. An app overrides it when
     * it has something structural to say — a second service, a database to wire —
     * which is why Ghost and the rest have their own files and the eight
     * one-container apps share one.
     */
    public function composeTemplate(): string
    {
        return 'server.docker.apps.simple';
    }

    /**
     * Environment this app needs beyond its URL, as key => value.
     *
     * Only for values the PANEL knows: a port, a public URL, a mode. Anything the
     * user has to decide belongs on the Environment screen, and anything secret
     * belongs in `generatedSecrets()`.
     *
     * @return array<string, string>
     */
    public function environment(Application $application): array
    {
        return [];
    }

    /**
     * The port INSIDE the app's own container, which nginx is proxied to.
     *
     * Declared even though `ComposeValidator::publishedPort()` can find it in
     * the rendered file, because the template needs it to write the port
     * mapping in the first place — and the two must be the same number.
     */
    abstract public function containerPort(): int;

    /**
     * The volumes this app keeps data in, as role => path inside the container.
     *
     * Roles, not names: the name is `sv-app-<id>_<role>`, decided at install
     * time because it needs the id. Roles are what the template refers to, so
     * a template never contains a site-specific string.
     *
     * @return array<string, string>
     */
    abstract public function volumeRoles(): array;

    /**
     * Environment keys that must be generated per site, never defaulted.
     *
     * A shipped default secret in a self-hosted panel is a vulnerability, not a
     * convenience: every install of that app on every server would share it.
     * The installer generates one value per key per site.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return [];
    }

    /**
     * Files this app cannot start without, as path-inside-the-container => body.
     *
     * Glance is the reason this exists: it exits on boot with
     * `reading /app/config/glance.yml: no such file or directory`, and a named
     * volume starts empty. An app that needs a config file present cannot be
     * handed an empty directory.
     *
     * These become a **bind mount inside the site's own directory**, not a named
     * volume — which is better than seeding a volume would be, for three reasons:
     * `ComposeValidator` already permits a bind whose source is inside the site
     * root, the File Manager becomes the editor with no extra work, and the file
     * lands somewhere a person can find it.
     *
     * The container path's parent directory is what gets mounted, so two files in
     * one directory mount once.
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
     * **Measured on a real box, because the failure is invisible.** The panel's
     * generic default is 512m, which suits a small container and silently breaks
     * a bigger one — and breaks it as a 502, not as anything mentioning memory:
     *
     *  - Metabase reported `Maximum memory available to JVM: 123.8 MB` and wrote
     *    an `hs_err_pid1.log`. A JVM sizes its heap at a quarter of the container
     *    limit, so 512m leaves it ~124MB against a documented 1GB minimum.
     *  - NocoDB exited with `Aborted (core dumped)`.
     *
     * Ghost and Wiki.js run inside 512m, so this is genuinely per-app rather than
     * a default that was simply too low.
     *
     * A user's own `memory_limit` on the application still wins: this is the
     * floor the app needs to start, not a policy about what it may have.
     */
    public function defaultMemoryLimit(): ?string
    {
        return null;
    }

    /**
     * The environment key this app is told its own URL through, or null.
     *
     * Null is a real answer and three of the first four apps give it: Metabase
     * and Wiki.js ask for their URL in their own setup wizard and keep it in the
     * database, so passing one would be a second source for a value the app
     * already owns.
     *
     * Declared here rather than inferred from the template, because two things
     * need it and neither can read a blade file usefully: `syncUrl()` has nothing
     * to reconcile when it is null, and the tests would otherwise be guessing at
     * a key name — which they were, and they failed on the second app, looking
     * for `URL` in a file that never had one.
     */
    public function urlEnvKey(): ?string
    {
        return null;
    }

    /**
     * The same two screens a plain container site does not get, and for the same
     * reasons: Clone copies served files, and a container's state is in its
     * volumes; Backup archives the document root and a database, and a container
     * has neither — the document root holds a compose file.
     *
     * Inherited from the reasoning in {@see DockerSiteType}, not duplicated: if
     * volume backup lands, both want it at once.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), ['app_clone', 'app_backup']));
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
     * The app decides its image, its port and its volumes — a field offering to
     * change any of those would be offering to break the compose file the panel
     * is about to write, which is why this returned an empty list for as long as
     * there were one-click apps.
     *
     * **Size is a different kind of question and it belongs here.** It is not a
     * fact about the software the way the image is; it is a fact about this
     * server and what else is on it, so only the person deploying can answer it.
     * Without these, a one-click installed at the app's floor and had to be
     * resized afterwards on a second screen — which is where they were, and where
     * nobody looked.
     *
     * The declared floor is what an empty memory field resolves to, so the help
     * text names THAT rather than the server default: telling somebody installing
     * Metabase that an empty field means 512m would be wrong, and wrong in the
     * direction that makes the app fail to start.
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
                // Two sentences apart, and the difference matters: an app with a
                // declared floor needs that figure, and somebody lowering it is
                // overriding a measurement rather than choosing a preference.
                // Metabase inside 512m reported 123.8 MB to the JVM and wrote a
                // crash log; NocoDB exited with `Aborted (core dumped)`. Both
                // failed as a 502 with nothing about memory in the panel.
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
     * The same two rules the Docker card and the Container screen use.
     *
     * Shared deliberately: a size accepted by one form and refused by another is
     * the panel disagreeing with itself, and the CPU bound in particular has to be
     * here or an over-provisioned one-click is a site that installs, fails at
     * `compose up`, and has to be deleted and made again.
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
