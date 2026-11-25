<?php

namespace App\Services\Applications\Types;

use App\Models\Registry;
use App\Rules\ContainerMemoryLimit;
use App\Rules\ContainerMountPath;
use App\Rules\ExistingDockerNetwork;
use App\Rules\NewDockerName;
use App\Rules\SingleLine;
use App\Rules\WithinHostCpus;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Docker\NetworkIsolation;
use App\Services\Server\HostCpus;

/**
 * An application that is a container.
 *
 * The panel writes a compose file, brings it up, and points the existing nginx
 * vhost (the same runtime-agnostic proxy template a Node app gets) at the port
 * it publishes.
 *
 * Few fields on purpose: only what the panel must own to wire the site up (image,
 * container port, network). A field belongs here only if compose cannot answer it
 * without the panel; everything else is compose's job. The network is panel-owned,
 * and a generated file that does not name it with `external: true` silently joins
 * a differently-prefixed network.
 */
class DockerSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'docker';
    }

    public function method(): string
    {
        return 'container';
    }

    public function servingProfile(): string
    {
        return 'docker';
    }

    public function category(): string
    {
        return 'custom';
    }

    public function icon(): string
    {
        return 'docker';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * No: a container that wants a database brings its own.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * The container's screens: the default set minus Clone.
     *
     * Clone copies served files, but a container's files are in its image and its
     * state is in its volumes, so a clone would look right with none of the data.
     * Backup is offered: `ArchiveVolumes` captures the volumes and `ArchiveFiles`
     * the compose file.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        // `app_container` and `app_compose` are added by the parent on
        // `servingProfile() === 'docker'`; adding them here left the one-click apps without them.
        return array_values(array_diff(parent::features(), ['app_clone']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            // Image-and-port versus a pasted compose file are alternatives; choosing up
            // front decides which other fields exist, so it is not advanced.
            $this->field('docker_mode', 'select', extra: [
                'default' => 'simple',
                'help' => __('application.help.docker_mode'),
                'options' => [
                    ['value' => 'simple', 'label' => __('application.options.docker_mode.simple')],
                    ['value' => 'compose', 'label' => __('application.options.docker_mode.compose')],
                ],
            ]),

            // Required only when there is no compose file (a pasted compose names its own images).
            $this->field('image', 'text', extra: [
                'depends_on' => 'docker_mode:simple',
                'placeholder' => 'nginx:1.27-alpine',
                'help' => __('application.help.image'),
                'required_without' => 'compose',
            ]),

            // Which stored registry credential to pull with. Deliberately NOT
            // `depends_on: docker_mode:simple`: images in a pasted compose file can be
            // private too. A panel-owned row, like the network below, so it has to be asked.
            $this->field('registry_id', 'select', extra: [
                'default' => '',
                'help' => __('application.help.registry_id'),
                'options' => $this->registryOptions(),
            ]),

            // The port *inside* the container. The published host port is allocated by
            // the panel (a user-picked one could collide, e.g. with the web server on 80;
            // with a compose file it is read from the resolved document).
            //
            // Optional and no default: left empty it is read from the image's EXPOSE, and
            // an image that declares none is refused rather than given 80.
            $this->field('container_port', 'number', extra: [
                'depends_on' => 'docker_mode:simple',
                'help' => __('application.help.container_port'),
            ]),

            // How big this container may be. Not advanced: everybody has an opinion on memory.
            // No `depends_on`: the compose override applies both limits to every service in a
            // PASTED file too.
            // No `default`: left empty, memory falls back to the server default and CPU means
            // no quota (a pre-filled CPU value would cap every site).
            $this->field('memory_limit', 'text', extra: [
                'placeholder' => (string) config('server.docker.default_memory_limit', '512m'),
                'help' => __('application.help.memory_limit', [
                    'default' => (string) config('server.docker.default_memory_limit', '512m'),
                ]),
            ]),

            // Cores, as Docker counts them. The help text names the host's actual core count.
            $this->field('cpu_limit', 'text', extra: [
                'placeholder' => __('application.placeholders.cpu_limit'),
                'help' => __('application.help.cpu_limit', [
                    'cores' => app(HostCpus::class)->count(),
                ]),
            ]),

            // The network to join, chosen from the ones on the Docker page. Compose cannot
            // discover a panel-created network: the generated file must name it with
            // `external: true`.
            //
            // Options are resolved here: a `select` declared with no options makes the create
            // form fall back to the runtime version list and offer PHP versions as networks.
            // Advanced: the common case is one container that talks to nobody.
            $this->field('docker_network', 'select', advanced: true, extra: [
                'depends_on' => 'docker_mode:simple',
                'help' => __('application.help.docker_network'),
                'options' => $this->networkOptions(),
            ]),

            // Or create one with a name you choose. A separate field, because the create form
            // has no choice that reveals an input.
            // Refused if the name already exists: adopting would attach the site to somebody
            // else's network (e.g. on a typo that collides).
            $this->field('docker_network_new', 'text', advanced: true, extra: [
                'depends_on' => 'docker_mode:simple',
                'placeholder' => 'ghost-net',
                'help' => __('application.help.docker_network_new'),
            ]),

            // A volume, created and mounted while creating the site. Name and path are
            // validated together (both or neither). One volume only; more are added on the
            // Container card.
            $this->field('volume_new', 'text', advanced: true, extra: [
                'depends_on' => 'docker_mode:simple',
                'placeholder' => 'ghost-content',
                'help' => __('application.help.volume_new'),
            ]),

            $this->field('volume_path', 'text', advanced: true, extra: [
                'depends_on' => 'docker_mode:simple',
                'placeholder' => '/var/lib/ghost/content',
                'help' => __('application.help.volume_path'),
            ]),

            // A pasted compose file. Left empty, the panel writes the file from the fields;
            // filled, this is the file, validated first (see ComposeValidator).
            $this->field('compose', 'textarea', extra: [
                'depends_on' => 'docker_mode:compose',
                'help' => __('application.help.compose'),
                'rows' => 14,
                'monospace' => true,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Permissive about the shape of a reference (host, port, namespace, tag and
            // digest are all legal); whitespace and shell metacharacters are refused
            // because the value reaches a command.
            'image' => ['required_without:compose', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/D'],
            // Not required: StoreApplicationRequest fills it from the image.
            'container_port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            // Container environment variables, written to the site's env file before the
            // first start; kept encrypted until then, never in `settings`.
            'env' => ['nullable', 'array', 'max:100'],
            'env.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/D'],
            'env.*.value' => ['nullable', 'string', 'max:65535'],

            // Volumes at create, the shape the Container card edits. `volume` may be left
            // out (named `<site>-<last path segment>`); absent altogether, the image's own
            // VOLUMEs are used.
            'volume_mounts' => ['nullable', 'array', 'max:20'],
            'volume_mounts.*.volume' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D'],
            // `SingleLine` and the positive pattern: the path is written into the generated
            // compose file, which no validator reads after this (a newline would add a YAML key).
            'volume_mounts.*.path' => ['required', 'string', 'max:255', new SingleLine, 'regex:'.ContainerMountPath::PATTERN, 'not_regex:/(^|\/)\.\.(\/|$)/', new ContainerMountPath],
            // The same rules as the Container settings screen. `WithinHostCpus` matters here:
            // otherwise the refusal lands as a failed provision rather than a field error.
            'memory_limit' => ['nullable', 'string', 'max:20', new ContainerMemoryLimit],
            'cpu_limit' => ['nullable', 'string', 'max:16', new WithinHostCpus],
            // Bounded (128 KB), because it reaches a parser and then a file.
            'compose' => ['nullable', 'string', 'max:131072'],
            // Never persisted and not validated against a list: a form affordance; the
            // either/or is enforced by `required_without:compose`.
            'docker_mode' => ['nullable', 'string', 'max:20'],

            // Checked against the table: a missing registry would pull anonymously and fail
            // on the next deploy.
            'registry_id' => ['nullable', 'integer', 'exists:registries,id'],

            // No application yet: Docker's built-ins and every site's private network are
            // refused; the site joins a shared network or its own.
            'docker_network' => ['nullable', 'string', 'max:255', new ExistingDockerNetwork],

            // A name to CREATE, so it must not exist yet; `prohibits` because picking and
            // naming are two answers to one question.
            'docker_network_new' => ['nullable', 'string', 'max:255', 'prohibits:docker_network', new NewDockerName('network')],

            'volume_new' => ['nullable', 'string', 'max:255', 'required_with:volume_path', new NewDockerName('volume')],
            'volume_path' => ['nullable', 'string', 'max:255', 'required_with:volume_new', new SingleLine, 'regex:'.ContainerMountPath::PATTERN, 'not_regex:/(^|\/)\.\.(\/|$)/', new ContainerMountPath],
        ];
    }

    /**
     * The registries this server can pull with, as chooser options.
     *
     * Gated on the server hosting containers, like `networkOptions()`: `fields()` is
     * serialised for every site type in the catalog listing.
     *
     * The empty option is not listed; it is the default and means "pull anonymously".
     *
     * @return list<array{value: string, label: string}>
     */
    private function registryOptions(): array
    {
        if (! app(ServerCapabilities::class)->hosts('docker')) {
            return [];
        }

        return Registry::query()
            ->orderBy('name')
            ->get(['id', 'name', 'registry'])
            ->map(fn ($registry): array => [
                'value' => (string) $registry->id,
                // The address alongside the name: a name says nothing about which registry it is.
                'label' => $registry->name.' — '.$registry->registry,
            ])
            ->all();
    }

    /**
     * The networks this server has, as chooser options.
     *
     * Gated on the server hosting containers: `fields()` is serialised for every site
     * type in the catalog listing, so ungated this would run `docker network ls` on a
     * LEMP box.
     *
     * Only networks the site may join: Docker's own and every site's private
     * `sv-app-<id>_*` network are left out, because the field's rule refuses them. The
     * empty choice is the default and means the site's own network.
     *
     * @return list<array{value: string, label: string}>
     */
    private function networkOptions(): array
    {
        if (! app(ServerCapabilities::class)->hosts('docker')) {
            return [];
        }

        return collect(app(DockerResources::class)->networks())
            ->reject(fn (array $network): bool => NetworkIsolation::refusal($network['name']) !== null)
            ->map(fn (array $network): array => [
                'value' => $network['name'],
                'label' => $network['name'],
            ])
            ->values()
            ->all();
    }
}
