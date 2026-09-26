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
            // Mail delivery. Optional: Mautic runs without it (sending off)
            // until SMTP is set up — requiring it kept a user with no SMTP
            // details from creating the site at all. A host given needs the
            // rest of the connection with it; see rules().
            $this->field('mailer_name', 'text', required: false,
                extra: ['autocomplete' => 'name', 'autocapitalize' => 'words',
                    'placeholder' => __('application.placeholders.mailer_name')]),
            $this->field('mailer_email', 'email', required: false,
                extra: ['placeholder' => __('application.placeholders.mailer_email')]),
            $this->field('mailer_host', 'text', required: false,
                extra: ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false',
                    // Was the literal 'e.g. smtp.example.com'. The prefix sat
                    // inside the value, so this one field said "e.g." while
                    // its nine neighbours showed a bare example — and being a
                    // literal it stayed English while the rest translated.
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
     * Mautic 7.2.0, measured 2026-09-18.
     *
     * Mautic is the one application here that publishes this machine-readably:
     * `app/release_metadata.json` in the release itself carries
     * `minimum_php_version: 8.2.0` and `maximum_php_version: 8.5.99`. Taken
     * from there rather than from the requirements page, because that file is
     * what ships with the build we install.
     *
     * The ceiling is real, not caution. Mautic refuses to run outright —
     * "Mautic does not support PHP version X at this time. To use Mautic, you
     * will need to downgrade." — so an out-of-range version is a dead install,
     * not a degraded one.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.2', 'max' => '8.5'];
    }
}
