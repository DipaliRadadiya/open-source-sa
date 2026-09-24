<?php

namespace App\Services\Applications\Types;

use App\Rules\ExistingDockerNetwork;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Docker\DockerResources;

/**
 * An application that is a container.
 *
 * The panel writes a compose file, brings it up, and points the existing nginx
 * vhost at the port it publishes. Nothing about the vhost is new — it is the
 * same template a Node application already gets, and it was already entirely
 * runtime-agnostic: `proxy_pass 127.0.0.1:{{ $appPort }}` plus the WebSocket
 * upgrade pair, with nothing in it that knows or asks what is listening.
 *
 * Few fields, not twenty. The temptation with "give every Docker option" is a
 * form field per `docker run` flag — registry, entrypoint, restart policy,
 * healthcheck, limits, user, volumes — which is a surface that grows every
 * time Docker adds one. The compose file is that surface already, so the
 * structured fields are only the ones the panel must own to wire the site up:
 * the image to run, the port inside the container to proxy to, and the network
 * to join. Everything else is compose's job.
 *
 * The test a field has to pass to be here is not "is it useful" — it is *can
 * compose answer this without the panel*. A healthcheck it can: the file says
 * it and Docker does it. A network it cannot, and that is the whole reason the
 * third field exists: the network is an object the PANEL created, on a page
 * Compose has never heard of, and a generated file that does not name it with
 * `external: true` silently joins a different network with the project name
 * prefixed. Without this field the Docker page could create networks that
 * nothing the panel builds was able to use.
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
     * No. A container that wants a database brings one — that is the whole
     * premise of the stack, and it is why this server manages no engine.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * What a container's screens are, and — more usefully — what they are not.
     *
     * Two are removed from the default set, both because they would appear to
     * work and quietly do the wrong thing. That is worse than their absence:
     * a screen that is missing prompts a question, and one that succeeds
     * without doing the job does not.
     *
     *  - **Clone.** Cloning copies the served files. A container's files are
     *    in its image and its state is in its volumes, so a clone would come
     *    up looking correct with none of the data. It arrives here by default
     *    because the default rule is `! needsDatabase()`, which is true of a
     *    container for a reason that has nothing to do with cloning.
     *  - **Backup.** The panel backs up the document root and a database. A
     *    container has neither — the document root holds a compose file, and
     *    the data is in volumes the backup never sees. Offering it would hand
     *    someone an archive they believe is their site. Volume backup is real
     *    work and belongs in its own phase, not as a side effect of this list.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), ['app_clone', 'app_backup']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            // Required only when there is no compose file. A pasted compose
            // names its own images — asking for one as well is asking the
            // same question twice and refusing an answer the user already
            // gave.
            $this->field('image', 'text', extra: [
                'placeholder' => 'nginx:1.27-alpine',
                'help' => __('application.help.image'),
                'required_without' => 'compose',
            ]),

            // The port *inside* the container. The published port on the host
            // is allocated by the panel and is not the user's to choose —
            // picking it would let two applications collide, and picking 80
            // would collide with the web server itself.
            // Same, and for a second reason: with a compose file the panel
            // reads the published port out of the resolved document instead,
            // so asking would let the two disagree.
            $this->field('container_port', 'number', extra: [
                'default' => 80,
                'help' => __('application.help.container_port'),
                'required_without' => 'compose',
            ]),

            // The network to join, chosen from the ones on the Docker page.
            //
            // This is the one field here that is not "an option Docker takes" —
            // it is a panel-owned object. Compose cannot discover a network the
            // panel created: the generated file has to name it with
            // `external: true` or the site joins a differently-prefixed network
            // of its own and reaches nothing. So the panel has to ask, the same
            // way it has to ask for `container_port`.
            //
            // Options resolved here rather than fetched by the form, because a
            // `select` the API declares with no options is not an empty
            // chooser — the create form falls back to the runtime version list
            // and offers PHP versions as networks.
            //
            // Advanced: the common case is one container that talks to nobody,
            // and a network picker on the front of the form implies a decision
            // most people do not have to make.
            $this->field('docker_network', 'select', advanced: true, extra: [
                'help' => __('application.help.docker_network'),
                'options' => $this->networkOptions(),
            ]),

            // Paste your own, and everything compose supports is supported.
            // Advanced, because the two fields above cover the common case and
            // a textarea of YAML on the create form would make a one-image
            // deploy look like work.
            //
            // Left empty, the panel writes the file from the fields. Filled,
            // this is the file — validated first, and the validator is where
            // the interesting part of this feature lives.
            $this->field('compose', 'textarea', advanced: true, extra: [
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
            // Deliberately permissive about the *shape* of a reference — a
            // registry host, a port, a namespace, a tag and a digest are all
            // legal, and a regex tight enough to be useful here is tight
            // enough to reject something real. What is refused is whitespace
            // and shell metacharacters, because the value reaches a command.
            'image' => ['required_without:compose', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'container_port' => ['required_without:compose', 'nullable', 'integer', 'min:1', 'max:65535'],
            // Bounded, because it reaches a parser and then a file. 128 KB is
            // far past any real compose file and far short of a problem.
            'compose' => ['nullable', 'string', 'max:131072'],
            // Checked against the box, not just the shape: `external: true`
            // makes Compose look the name up, so a network that is not there
            // is a container that will not start — and the refusal has to
            // arrive on the field rather than on the next deploy.
            'docker_network' => ['nullable', 'string', 'max:255', new ExistingDockerNetwork],
        ];
    }

    /**
     * The networks this server has, as chooser options.
     *
     * Gated on the server actually hosting containers, because `fields()` is
     * serialised for EVERY site type in the catalog listing — ungated this
     * would run `docker network ls` on a LEMP box, on a request that has
     * nothing to do with Docker, to build options for a card that is not shown.
     *
     * Docker's own three are offered alongside the panel's. `bridge` is a
     * legitimate answer and is also what null means, so it is not listed
     * twice — the empty choice is the default, and the built-ins that cannot
     * do name resolution are left out rather than offered as a trap.
     *
     * @return list<array{value: string, label: string}>
     */
    private function networkOptions(): array
    {
        if (! app(ServerCapabilities::class)->hosts('docker')) {
            return [];
        }

        return collect(app(DockerResources::class)->networks())
            ->reject(fn (array $network): bool => $network['built_in'])
            ->map(fn (array $network): array => [
                'value' => $network['name'],
                'label' => $network['name'],
            ])
            ->values()
            ->all();
    }
}
