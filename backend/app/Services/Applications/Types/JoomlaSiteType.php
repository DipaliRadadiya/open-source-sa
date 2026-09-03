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
            // Deliberately no default, because JoomlaInstaller::tablePrefix()
            // already generates a random one when this arrives empty — "as
            // Joomla's own installer generates", per its docblock, so the
            // tables stay apart if the database is ever shared.
            //
            // Shipping `jos_` here would have filled the box on every form and
            // made that branch unreachable, quietly replacing a per-site random
            // prefix with the single most guessable value in Joomla's history.
            // A predictable prefix is what a blind SQL injection needs to name
            // a table. The help text says what empty means; anyone who wants a
            // fixed prefix can still type one.
            $this->field('table_prefix', 'text', advanced: true, extra: [
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
