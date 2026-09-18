<?php

namespace App\Services\Applications\Types;

use App\Services\Runtime\AppPackageCatalog;

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
     * What the release being installed says it runs on.
     *
     * Read from the package rather than written down here. The installer
     * resolves `latest`, so a range typed into this file is a transcription
     * that ages: it read `20.19`-`24` while n8n 1.x declared
     * `>=20.19 <= 24.x`, and stayed there after 2.x moved to `>=24.0.0` with
     * no ceiling at all. The two facts have to agree and nothing checked that
     * they did, because the failure arrives later and somewhere else - a site
     * that installs without complaint and then refuses to start.
     *
     * The literal below is the fallback, not the answer: it is what a server
     * with no egress or an unrefreshed catalog offers. Deliberately the narrow
     * reading of the release current when it was written - being too strict
     * costs somebody a version in a dropdown, being too loose hands them a site
     * that will not boot.
     */
    public function supportedNodeRange(): ?array
    {
        return app(AppPackageCatalog::class)->nodeRange($this->npmPackage())
            ?? ['min' => '24', 'max' => null];
    }

    /**
     * The npm package this site type installs, so the catalog knows what to
     * ask the registry about.
     */
    public function npmPackage(): ?string
    {
        return 'n8n';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->nodeFields());
    }
}
