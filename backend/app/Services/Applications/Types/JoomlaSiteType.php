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
            // Generated per site when left blank, as Joomla's own installer
            // does, so tables stay apart if the database is ever shared.
            // `jml_`, an operator decision (2026-09-03): a prefix someone can
            // recognise in phpMyAdmin beats one they cannot.
            //
            // Objection recorded, because the code it overrides is deliberate.
            // JoomlaInstaller::tablePrefix() generates a random per-site prefix
            // when this arrives empty — "as Joomla's own installer generates" —
            // and a predictable prefix is what a blind SQL injection needs in
            // order to name a table. Shipping a default means that branch is
            // only reached by someone who clears the box on purpose.
            //
            // The trade is defensible: it is the same bet every other control
            // panel makes, the random branch still exists, and the help text
            // below says what clearing the field gets you.
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
            'table_prefix' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9_]+$/'],
        ];
    }
}
