<?php

namespace App\Services\Applications\Types;

/**
 * Nextcloud — self-hosted file sync and share.
 */
class NextcloudSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'nextcloud';
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'php';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function popular(): bool
    {
        return true;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'admin_user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Nextcloud's own wizard rates password strength and asks for
            // "strong"; this is the floor the API will accept.
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }

    /**
     * Nextcloud 35.0.0, measured 2026-09-18.
     *
     * Read from `lib/versioncheck.php` in the release, which is Nextcloud
     * refusing to boot rather than documentation describing it: below 80300 it
     * prints "requires at least PHP 8.3", and from 80600 it prints "not
     * compatible with PHP>=8.6". So the supported band is 8.3 up to and
     * including 8.5.
     *
     * Note the ceiling is expressed as "< 8.6" upstream, which is why it is
     * 8.5 here and not 8.4: guessing a tighter bound would refuse a version
     * Nextcloud accepts.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.3', 'max' => '8.5'];
    }

    /**
     * CalDAV/CardDAV discovery and the rest of `/.well-known`, as Nextcloud's
     * own `.htaccess` routes them. On nginx and OpenLiteSpeed both answered
     * 404, so calendar and contact clients could not find the server, and
     * Nextcloud's own setup check failed on it (nginx test server).
     *
     * @return array{redirects: array<string, string>, fallback: string|null}
     */
    public function wellKnownRoutes(): array
    {
        return [
            'redirects' => ['carddav' => '/remote.php/dav/', 'caldav' => '/remote.php/dav/'],
            'fallback' => '/index.php',
        ];
    }

    /**
     * ES modules. nginx served `.mjs` as application/octet-stream, which a
     * browser refuses to run as a module — some Nextcloud apps broke.
     *
     * @return array<string, string>
     */
    public function mimeTypes(): array
    {
        return ['mjs' => 'text/javascript'];
    }
}
