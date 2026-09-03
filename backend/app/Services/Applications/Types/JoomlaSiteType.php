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
            // A default, not a placeholder: this value is submitted and ends
            // up in the database schema, so ghost text that is never sent
            // would create the site with no prefix at all. `jos_` is Joomla's
            // historic convention and matches what the panel already does for
            // WordPress (`wp_`), Moodle (`mdl_`) and PrestaShop (`ps_`).
            //
            // Joomla's own installer suggests a random prefix instead, which is
            // marginally better against a blind SQL injection that has to guess
            // table names. Consistency won: a panel whose four PHP CMSes prefix
            // three predictably and one randomly is harder to reason about than
            // one that is uniform, and the field stays editable for anyone who
            // wants a random one.
            $this->field('table_prefix', 'text', advanced: true, extra: ['default' => 'jos_']),
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
