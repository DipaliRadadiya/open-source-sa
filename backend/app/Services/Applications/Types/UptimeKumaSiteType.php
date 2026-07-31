<?php

namespace App\Services\Applications\Types;

/**
 * Uptime Kuma — uptime monitoring.
 *
 * No admin fields: Uptime Kuma has no setup CLI, so the first visitor creates
 * the administrator. The tagline says so, because a site that is reachable and
 * unclaimed is not a state to leave someone guessing about.
 */
class UptimeKumaSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'uptimekuma';
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
        return 'monitoring';
    }

    public function icon(): string
    {
        return 'uptimekuma';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * SQLite, inside its own directory.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->nodeFields());
    }
}
