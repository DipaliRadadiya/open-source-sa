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
     * n8n documents a closed range — Node 20.19 to 24.x inclusive — and it is
     * the ceiling that matters here: n8n refuses to start on a version outside
     * it rather than warning, so a too-new Node is as fatal as a too-old one.
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '20.19', 'max' => '24'];
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->nodeFields());
    }
}
