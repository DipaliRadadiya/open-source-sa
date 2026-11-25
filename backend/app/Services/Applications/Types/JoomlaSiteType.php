<?php

namespace App\Services\Applications\Types;

/**
 * Joomla — content management system.
 */
class JoomlaSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'joomla';
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
        return 'cms';
    }

    public function icon(): string
    {
        return 'joomla';
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
            $this->field('site_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_name')]),
            $this->field('admin_name', 'text', required: true, extra: ['default' => 'Administrator']),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            // Default `jml_` is easy to recognise in phpMyAdmin. Clearing the field makes
            // JoomlaInstaller::tablePrefix() generate a random per-site prefix instead; a
            // predictable prefix helps a blind SQL injection name a table.
            $this->field('table_prefix', 'text', advanced: true, extra: [
                'default' => 'jml_',
                'help' => __('application.help.table_prefix_random'),
            ]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Joomla's own minimum is 12.
            'admin_password' => ['required', 'string', 'min:12'],
            // Must start with a letter, as Joomla's own installer requires.
            'table_prefix' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ];
    }

    /**
     * Joomla's `composer.json` declares `"php": "^8.3.0"`.
     *
     * No ceiling: Joomla states none, and inventing one would refuse a supported PHP version.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.3', 'max' => null];
    }
}
