<?php

namespace App\Services\Applications\Types;

use App\Models\Application;

/**
 * Grafana — dashboards and alerts over whatever you point it at.
 *
 * The one-container shape, with two things that matter more than the shape.
 *
 * **The admin password is generated, not `admin`.** Grafana's documented default
 * is admin/admin with a change-me prompt on first login, which on a public URL is
 * a race between the owner and everybody else. `GF_SECURITY_ADMIN_PASSWORD` is
 * read on every boot, so the panel sets one per site and the Credentials panel is
 * where you read it. This is the difference between this card and following
 * Grafana's own quickstart.
 *
 * **`GF_SERVER_ROOT_URL` is not decoration.** Grafana builds every absolute link
 * from it — alert notifications, share URLs, OAuth redirects, the snapshot
 * links — and behind a proxy it otherwise guesses `localhost:3000`. So a site
 * whose URL is not passed sends mail linking somewhere nobody can reach, and
 * `syncUrl()` rewrites it when the primary domain changes.
 *
 * `/var/lib/grafana` is the SQLite database, the dashboards, the users and the
 * alert rules — the whole instance apart from provisioning files. The image
 * declares it as a VOLUME, so leaving it unnamed would hand the site an anonymous
 * volume the panel can neither show nor guard.
 */
class GrafanaSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'grafana';
    }

    public function category(): string
    {
        return 'analytics';
    }

    public function icon(): string
    {
        return 'grafana';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * Its own template, and only because of the password.
     *
     * The shared one-container template renders `$environment` and not
     * `$secrets`, so an app whose admin password is generated per site cannot
     * use it. Everything else here is the shared shape.
     */
    public function composeTemplate(): string
    {
        return 'server.docker.apps.grafana';
    }

    /** Grafana's own default, and what the image exposes. */
    public function containerPort(): int
    {
        return 3000;
    }

    /**
     * Every absolute link Grafana emits comes from this.
     */
    public function urlEnvKey(): ?string
    {
        return 'GF_SERVER_ROOT_URL';
    }

    /**
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'data' => '/var/lib/grafana',
        ];
    }

    /**
     * The initial admin password, so the site is never admin/admin on a public
     * URL. Read on every boot rather than only at first run, which is why
     * rotating it in the compose file would actually change it — and why the
     * installer deliberately keeps the stored value instead of regenerating.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['GF_SECURITY_ADMIN_PASSWORD'];
    }

    /**
     * @return array<string, string>
     */
    public function environment(Application $application): array
    {
        return [
            // The admin account the password above belongs to. Named explicitly
            // rather than left to the default, so the Credentials panel and the
            // login form agree without the reader having to know Grafana.
            'GF_SECURITY_ADMIN_USER' => 'admin',

            // Served at the domain root, not under a path. Grafana needs telling:
            // with this true and a root URL that has no path, it strips a prefix
            // that was never there and every asset 404s.
            'GF_SERVER_SERVE_FROM_SUB_PATH' => 'false',

            // Off by default. Grafana phones home for version checks and plugin
            // metadata; a self-hosted panel should not opt somebody into that
            // silently, and the toggle is in the UI for anyone who wants it.
            'GF_ANALYTICS_REPORTING_ENABLED' => 'false',
            'GF_ANALYTICS_CHECK_FOR_UPDATES' => 'false',

            // Anonymous read would make every dashboard public the moment the
            // site has a domain, which is not what "install Grafana" asks for.
            'GF_AUTH_ANONYMOUS_ENABLED' => 'false',
        ];
    }
}
