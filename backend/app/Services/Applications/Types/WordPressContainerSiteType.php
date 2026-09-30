<?php

namespace App\Services\Applications\Types;

use App\Models\Application;

/**
 * WordPress, as a container, with its own MariaDB.
 *
 * **Why this exists next to `WordPressSiteType`.** That one installs WordPress on
 * the host as PHP, which a Docker-stack server cannot do: it serves containers and
 * nothing else, so the PHP card is filtered out of its catalog and a Docker box had
 * no WordPress at all. The two never appear together — each is filtered out on the
 * other's stack — so both carry the title "WordPress" and a user sees one card.
 *
 * The name differs because `name()` is the identifier: `SiteTypeManager::find()`
 * resolves by it and the catalog's translation keys are built from it, so two types
 * called `wordpress` would be one type with the other shadowed.
 *
 * **The HTTPS trap, which is why this is not four lines.** nginx terminates TLS and
 * proxies to the container over plain HTTP, so PHP sees no `HTTPS` in `$_SERVER`.
 * WordPress then builds every asset URL as `http://`, which a browser blocks as
 * mixed content — and if `siteurl` in the database says `https`, the two disagree
 * and every request becomes a redirect loop. The container vhost sends
 * `X-Forwarded-Proto`, so the config snippet reads that and sets `HTTPS` itself.
 *
 * **Why the URL is forced rather than left in the database.** WordPress normally
 * owns `siteurl`/`home`, and a panel-side domain change then leaves the site
 * serving the old host — the classic "changed my domain, now wp-admin redirects me
 * away" lockout. Defining `WP_HOME` and `WP_SITEURL` from an environment variable
 * makes the panel's domain the single source, and {@see urlEnvKey()} is what gets
 * that variable rewritten when the domain moves. The cost is that Settings →
 * General shows those fields read-only, which for a site whose domain the panel
 * manages is the honest arrangement rather than a second place to set it.
 *
 * Salts are left to the image. Its entrypoint generates the eight keys from
 * `/dev/urandom` when they are not supplied and writes them into `wp-config.php`,
 * which lives in the volume — so they are unique per site and stable across
 * restarts. Generating them here would add eight rows to the Credentials panel to
 * replace something already correct.
 */
class WordPressContainerSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'wordpress_container';
    }

    public function category(): string
    {
        return 'cms';
    }

    public function icon(): string
    {
        return 'wordpress';
    }

    public function popular(): bool
    {
        return true;
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.wordpress';
    }

    /** Apache inside the image. */
    public function containerPort(): int
    {
        return 80;
    }

    /**
     * A real variable, read by the config snippet to define `WP_HOME` and
     * `WP_SITEURL` — not a marker. Returning it is also what makes
     * `DockerAppInstaller::syncUrl()` re-render the compose file when the primary
     * domain changes, which is the whole reason the URL is not left in the
     * database.
     */
    public function urlEnvKey(): ?string
    {
        return 'WORDPRESS_SITE_URL';
    }

    /**
     * `/var/www/html` is the whole install — uploads, plugins, themes and
     * `wp-config.php` with its salts. WordPress writes into its own webroot, so
     * without this a rebuild loses every upload and re-runs setup against a
     * populated database.
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'app' => '/var/www/html',
            'db' => '/var/lib/mysql',
        ];
    }

    /**
     * Two: WordPress's own database user, and MariaDB's root account. The image
     * needs root to create the schema; WordPress itself must never have it.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD', 'MARIADB_ROOT_PASSWORD'];
    }

    /**
     * @return array<string, string>
     */
    public function environment(Application $application): array
    {
        return [
            // Apache runs as www-data (33) in the image and the volume inherits
            // that, so there is nothing to set here — unlike the linuxserver
            // images, which need PUID/PGID.
            'WORDPRESS_DB_HOST' => 'db',
            'WORDPRESS_DB_NAME' => 'wordpress',
            'WORDPRESS_DB_USER' => 'wordpress',
        ];
    }
}
