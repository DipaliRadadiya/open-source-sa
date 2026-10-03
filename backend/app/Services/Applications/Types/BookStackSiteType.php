<?php

namespace App\Services\Applications\Types;

use App\Models\Application;

/**
 * BookStack — documentation, organised as shelves, books and pages.
 *
 * Wiki.js is already here and this is not a duplicate of it: Wiki.js is a
 * markdown wiki with a page tree, BookStack is a WYSIWYG documentation tool with
 * a fixed three-level hierarchy people either want or do not. They attract
 * different requests, which is why both are worth a card.
 *
 * **`APP_URL` is required, not advisory.** BookStack refuses to start without it
 * and builds every link, asset path and redirect from it, so a site whose domain
 * changes needs it rewritten — {@see AbstractDockerAppType::urlEnvKey()} and the
 * `syncUrl()` that acts on it.
 *
 * **`APP_KEY` has a format, and getting it wrong fails at boot.** It is a Laravel
 * application key: `base64:` followed by exactly 32 base64-encoded bytes, used to
 * encrypt sessions and any stored credential. The panel's generator produces a
 * 32-character random string — which is 32 bytes — so the template base64-encodes
 * it rather than passing it raw. Passed raw, BookStack dies with a message about
 * an unsupported cipher, which says nothing about the key.
 *
 * Losing the key is losing every session and every encrypted setting, which is
 * why it is a stored secret and not something regenerated per deploy.
 *
 * The linuxserver image keeps its configuration, uploads and generated files in
 * `/config`, and nothing useful in the webroot — so that is the volume, plus the
 * database's own.
 */
class BookStackSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'bookstack';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'bookstack';
    }

    public function popular(): bool
    {
        return true;
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.bookstack';
    }

    /** nginx inside the linuxserver image. */
    public function containerPort(): int
    {
        return 80;
    }

    /**
     * Required by BookStack, and the source of every link it renders.
     */
    public function urlEnvKey(): ?string
    {
        return 'APP_URL';
    }

    /**
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'config' => '/config',
            'db' => '/var/lib/mysql',
        ];
    }

    /**
     * Three: the application key, the database user's password, and MariaDB's own
     * root account. The image needs root to create the schema; BookStack itself
     * must never have it.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['APP_KEY', 'DATABASE_PASSWORD', 'MARIADB_ROOT_PASSWORD'];
    }

    /**
     * @return array<string, string>
     */
    public function environment(Application $application): array
    {
        return [
            // The linuxserver images run as this uid/gid inside the container and
            // chown `/config` to it on start. Left unset they default to 911,
            // which is fine — named explicitly because the volume's ownership is
            // the thing that breaks silently when it is not.
            'PUID' => '1000',
            'PGID' => '1000',
            'TZ' => 'UTC',
        ];
    }
}
