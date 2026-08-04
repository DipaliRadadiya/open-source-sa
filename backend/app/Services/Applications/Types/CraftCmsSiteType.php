<?php

namespace App\Services\Applications\Types;

use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * Craft CMS — content management for developers.
 */
class CraftCmsSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'craftcms';
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
        return 'craftcms';
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * Craft keeps its source beside a small public directory. Serving the root
     * instead would publish that source, `.env` included.
     */
    public function defaultWebRoot(): string
    {
        return '/web';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('site_name', 'text', required: true),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('language', 'select', advanced: true, extra: [
                'default' => 'en-US',
                'options' => FieldOptions::asOptions(FieldOptions::hyphenLocales()),
            ]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Craft's own minimum is 6; ours is stricter.
            'admin_password' => ['required', 'string', 'min:10'],
            'language' => ['nullable', 'string', Rule::in(FieldOptions::hyphenLocales())],
        ];
    }

    /**
     * Craft reads a `.env` at the project root — unusual for a marketplace
     * install, and the reason this overrides the default. Database credentials
     * and the security key live there, so hiding the screen would mean the
     * only way to change them is a file manager.
     */
    public function features(): array
    {
        return [...parent::features(), 'app_environment'];
    }
}
