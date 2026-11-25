<?php

namespace App\Services\Applications\Types;

/**
 * Mautic — marketing automation.
 */
class MauticSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'mautic';
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
        return 'marketing';
    }

    public function icon(): string
    {
        return 'megaphone';
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('site_title', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_title')]),
            $this->field('admin_first_name', 'text', required: true, extra: ['default' => 'Admin']),
            $this->field('admin_last_name', 'text', required: true, extra: ['default' => 'User']),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            // Mail delivery. Optional: Mautic runs without it (sending off) until SMTP is set up.
            // A host given needs the rest of the connection with it; see rules().
            $this->field('mailer_name', 'text', required: false,
                extra: ['autocomplete' => 'name', 'autocapitalize' => 'words',
                    'placeholder' => __('application.placeholders.mailer_name')]),
            $this->field('mailer_email', 'email', required: false,
                extra: ['placeholder' => __('application.placeholders.mailer_email')]),
            $this->field('mailer_host', 'text', required: false,
                extra: ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false',
                    'placeholder' => __('application.placeholders.mailer_host')]),
            $this->field('mailer_port', 'number', required: false,
                extra: ['min' => 1, 'max' => 65535,
                    'placeholder' => __('application.placeholders.mailer_port')]),
            $this->field('mailer_username', 'text', required: false,
                extra: ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false',
                    'placeholder' => __('application.placeholders.mailer_username')]),
            $this->field('mailer_password', 'password', required: false,
                extra: ['autocomplete' => 'current-password']),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_title' => ['required', 'string', 'max:255'],
            'admin_first_name' => ['required', 'string', 'max:100'],
            'admin_last_name' => ['required', 'string', 'max:100'],
            'admin_user' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
            'mailer_name' => ['nullable', 'string', 'max:255'],
            'mailer_email' => ['nullable', 'email', 'max:255'],
            'mailer_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'mailer_port' => ['nullable', 'required_with:mailer_host', 'integer', 'min:1', 'max:65535'],
            'mailer_username' => ['nullable', 'string', 'max:255'],
            'mailer_password' => ['nullable', 'required_with:mailer_username', 'string', 'max:500'],
        ];
    }

    /**
     * Mautic 7.2.0.
     *
     * Taken from `app/release_metadata.json` in the release (`minimum_php_version: 8.2.0`,
     * `maximum_php_version: 8.5.99`). The ceiling is real: Mautic refuses to run on a newer PHP.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.2', 'max' => '8.5'];
    }

    /**
     * Paths the web server must never serve.
     *
     * Mautic protects itself with an Apache `.htaccess` that nginx never reads and OpenLiteSpeed
     * only where the vhost allows it, so the same rules are denied here. Written without lookaheads
     * (OpenLiteSpeed contexts): PHP below the top level, the helper scripts beside index.php, and
     * `var/` (logs, cache, mail spool). `bin/` is denied whole: `bin/console` has no `.php` ending,
     * so nginx would serve its source as a download.
     *
     * @return array<int, string>
     */
    public function deniedPaths(): array
    {
        return [
            '^/[^/]+/.*\\.(php[0-9]?|phtml|phar)$',
            '^/(autoload|ecs|importmap|phpstan-bootstrap)\\.php$',
            '^/var/',
            '^/bin/',
            '/\\.env',
            '^/composer\\.(json|lock)$',
        ];
    }

    /**
     * Mautic reads a `.env` beside its code, like Craft and Statamic. ApplicationEnvironment reads
     * the file the framework actually loads before the one it would create.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return [...parent::features(), 'app_environment'];
    }
}
