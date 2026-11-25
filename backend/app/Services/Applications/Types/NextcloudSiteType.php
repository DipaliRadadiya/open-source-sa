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
            // Floor the API accepts; Nextcloud's own wizard asks for a "strong" one.
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }

    /**
     * Nextcloud 35.0.0: `lib/versioncheck.php` refuses below 80300 and from 80600, so the band is
     * 8.3 up to and including 8.5 (upstream's ceiling is "< 8.6"; a tighter bound would refuse
     * a version Nextcloud accepts).
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.3', 'max' => '8.5'];
    }

    /**
     * CalDAV/CardDAV discovery and the rest of `/.well-known`, as Nextcloud's own `.htaccess`
     * routes them. Without them nginx and OpenLiteSpeed answer 404 and clients cannot find the server.
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
     * ES modules: nginx would serve `.mjs` as application/octet-stream, which browsers refuse to run.
     *
     * @return array<string, string>
     */
    public function mimeTypes(): array
    {
        return ['mjs' => 'text/javascript'];
    }
}
