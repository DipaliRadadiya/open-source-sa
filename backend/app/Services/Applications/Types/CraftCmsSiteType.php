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
     * A floor and no ceiling, the same shape as Statamic's.
     *
     * Craft 5 needs PHP ^8.2. The installer runs `composer create-project` unpinned, so on
     * PHP 8.0/8.1 Composer would silently resolve backwards and install Craft 4.
     * No ceiling: Craft states none.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.2', 'max' => null];
    }

    /**
     * Craft keeps its source beside a small public directory. Serving the root
     * instead would publish that source, `.env` included.
     */
    public function defaultWebRoot(): string
    {
        return '/web';
    }

    /**
     * The only value that works: the installer builds the project above the web root and Craft's
     * front controller is in `web/`. Any other value (`/` would expose `.env`) is unsafe.
     */
    public function fixedWebRoot(): ?string
    {
        return $this->defaultWebRoot();
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('site_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_name')]),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('language', 'select', advanced: true, extra: [
                'default' => 'en-US',
                'options' => FieldOptions::localeOptions(FieldOptions::hyphenLocales()),
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
     * Craft reads a `.env` at the project root (database credentials, security key), so the
     * environment screen is enabled.
     */
    public function features(): array
    {
        // Craft ships a queue (`craft queue/listen`), so workers too.
        return [...parent::features(), 'app_environment', 'app_worker'];
    }
}
