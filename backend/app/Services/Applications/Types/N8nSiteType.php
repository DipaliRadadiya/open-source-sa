<?php

namespace App\Services\Applications\Types;

/**
 * n8n — workflow automation.
 *
 * No admin fields: n8n's owner account is created by the first person to open
 * the site, the same as Uptime Kuma.
 *
 * Fair-code under the Sustainable Use License, not open source. Self-hosting
 * for your own use is what it permits; redistributing it as part of a hosted
 * offering is not. The tagline says so.
 */
class N8nSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'n8n';
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'node';
    }

    public function category(): string
    {
        return 'automation';
    }

    public function icon(): string
    {
        return 'n8n';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * SQLite by default, inside the site's own directory.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * n8n's own documented minimum is 2 GB of RAM. Under the server's 512M
     * default the unit is killed on startup, restarts, hits its start limit
     * and stops — which reaches the browser as a 502 on a site that installed
     * without complaint.
     */
    public function defaultMemoryMax(): ?string
    {
        return '2G';
    }

    /**
     * Node 24 only, because that is what the version being installed accepts.
     *
     * n8n 2.x declares `engines: {node: ">=24.0.0"}`, so the old floor of 20.19
     * — correct for 1.x — would now let someone create a site on a Node the
     * application refuses. The ceiling stays closed for the reason it was
     * closed before: n8n refuses to start outside its range rather than
     * warning, so a too-new Node is as fatal as a too-old one, and an open
     * `max` invites the identical failure from the other end.
     *
     * One value in the range is the point. The picker filters to versions in
     * range, so an n8n site offers exactly one Node and nobody has to know why
     * — the operator does not care which Node it is, only that the app runs.
     *
     * ⚠️ This is coupled to `server.installers.n8n.version`, which is `latest`
     * and therefore moves on its own. When n8n's next major raises its floor,
     * this number has to move with it or new installs get an application that
     * will not start on the only Node the form allows. Pinning the major, or
     * reading `engines.node` off the registry at install time, is what would
     * remove the coupling.
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '24', 'max' => '24'];
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->nodeFields());
    }
}
