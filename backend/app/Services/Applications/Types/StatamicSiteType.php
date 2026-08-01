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
     * Content is stored in files, so there is nothing for a database to hold.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * A Laravel application: the source sits beside a small public directory,
     * and serving the root instead would publish `.env`.
     */
    public function defaultWebRoot(): string
    {
        return '/public';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_email', 'email', required: true),
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
     * Statamic is Laravel underneath, so it has a `.env` like any Laravel
     * application even though it arrives as a one-click install.
     */
    public function features(): array
    {
        return [...parent::features(), 'app_environment'];
    }
}
