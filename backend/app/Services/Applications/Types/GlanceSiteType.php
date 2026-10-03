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

    /**
     * No named volume: the configuration is a file somebody edits, so it lives in
     * the site's own directory as a bind mount. See `starterFiles()`.
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [];
    }

    /**
     * Glance exits on boot without this file — `reading /app/config/glance.yml:
     * no such file or directory` — so an empty directory is not a usable start.
     *
     * The starter is upstream's minimal shape: one page, one column, a clock and
     * the server's own stats. Enough that the site answers on first visit, and
     * obvious enough to edit.
     *
     * @return array<string, string>
     */
    public function starterFiles(): array
    {
        return [
            '/app/config/glance.yml' => <<<'YAML'
                # Glance configuration. Edit this file in the panel's File Manager,
                # then restart the site's container to apply it.
                # Reference: https://github.com/glanceapp/glance/blob/main/docs/configuration.md
                pages:
                  - name: Home
                    columns:
                      - size: full
                        widgets:
                          - type: clock
                            hour-format: 24h
                          - type: server-stats
                            servers:
                              - type: local
                                name: This server
                YAML,
        ];
    }
}
