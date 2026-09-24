<?php

namespace App\Services\Applications\Types;

/**
 * An application that is a container.
 *
 * The panel writes a compose file, brings it up, and points the existing nginx
 * vhost at the port it publishes. Nothing about the vhost is new — it is the
 * same template a Node application already gets, and it was already entirely
 * runtime-agnostic: `proxy_pass 127.0.0.1:{{ $appPort }}` plus the WebSocket
 * upgrade pair, with nothing in it that knows or asks what is listening.
 *
 * Two fields, not twenty. The temptation with "give every Docker option" is a
 * form field per `docker run` flag — registry, entrypoint, restart policy,
 * healthcheck, limits, user, volumes — which is a surface that grows every
 * time Docker adds one. The compose file is that surface already, so the
 * structured fields are only the ones the panel must own to wire the site up:
 * the image to run, and the port inside the container to proxy to. Everything
 * else is compose's job, and an editor for it comes next.
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
            $this->field('image', 'text', required: true, extra: [
                'placeholder' => 'nginx:1.27-alpine',
                'help' => __('application.help.image'),
            ]),

            // The port *inside* the container. The published port on the host
            // is allocated by the panel and is not the user's to choose —
            // picking it would let two applications collide, and picking 80
            // would collide with the web server itself.
            $this->field('container_port', 'number', required: true, extra: [
                'default' => 80,
                'help' => __('application.help.container_port'),
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
            'image' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'container_port' => ['required', 'integer', 'min:1', 'max:65535'],
            // Bounded, because it reaches a parser and then a file. 128 KB is
            // far past any real compose file and far short of a problem.
            'compose' => ['nullable', 'string', 'max:131072'],
        ];
    }
}
