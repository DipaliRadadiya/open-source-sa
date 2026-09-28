<?php

namespace App\Services\Applications\Types;

/**
 * Glance — a configurable dashboard for feeds, monitors and bookmarks.
 *
 * Configured entirely by a YAML file in `/app/config`, which the panel mounts as
 * a volume so it survives a rebuild. The File Manager is where somebody edits
 * it; there is nothing for the panel to ask on the create form.
 */
class GlanceSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'glance';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function icon(): string
    {
        return 'glance';
    }

    public function containerPort(): int
    {
        return 8080;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'config' => '/app/config',
        ];
    }
}
