<?php

namespace App\Services\Applications\Types;

/**
 * Statamic — flat-file CMS built on Laravel.
 */
class StatamicSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'statamic';
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
        return 'statamic';
    }

    public function popular(): bool
    {
        return false;
    }

    /**
     * A floor and no ceiling: Statamic requires PHP 8.3+ and the installer runs
     * `composer create-project statamic/statamic` unpinned. Nothing upstream states a ceiling,
     * and inventing one would refuse a version that works the day PHP ships it.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.3', 'max' => null];
    }

    /**
     * Content is stored in files, so there is nothing for a database to hold.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * A Laravel application: serving the root instead of `/public` would publish `.env`.
     */
    public function defaultWebRoot(): string
    {
        return '/public';
    }

    /**
     * The only web root it can be served from. `StatamicInstaller` builds the project above it, so
     * anything else points the vhost at a missing directory or, at `/`, exposes `.env`, `vendor/`
     * and `storage/`.
     */
    public function fixedWebRoot(): ?string
    {
        return $this->defaultWebRoot();
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }

    /**
     * Laravel's encryption key: a new one signs everybody out and leaves anything encrypted with
     * the old one unreadable.
     */
    public function lockedEnvironmentKeys(): array
    {
        return ['APP_KEY'];
    }

    public function features(): array
    {
        // Statamic is Laravel underneath, so it has a queue worker too.
        return [...parent::features(), 'app_environment', 'app_worker'];
    }
}
