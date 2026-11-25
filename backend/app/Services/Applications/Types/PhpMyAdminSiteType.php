<?php

namespace App\Services\Applications\Types;

/**
 * phpMyAdmin — a database client, not a site.
 *
 * No admin account or database of its own: whoever opens it signs in with their own database
 * credentials, so the create form is just the common fields.
 */
class PhpMyAdminSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'phpmyadmin';
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
        return 'utility';
    }

    public function icon(): string
    {
        return 'database';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * It reads the databases already on the server; one of its own would sit empty and add a
     * standing credential nobody asked for.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->phpFields());
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * A tool, not a site: it holds no content, so backup and clone make no sense; reinstalling is
     * the recovery path.
     *
     * Password protection stays: an exposed phpMyAdmin is a login page for every database on the
     * box, and a second lock is the hardening step that matters most here.
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), [
            'app_backup',
            'app_clone',
        ]));
    }

    /**
     * phpMyAdmin 5.2.3: `composer.json` declares `"php": "^7.2.5 || ^8.0"`.
     *
     * 🔴 **No ceiling, deliberately.** `^8.0` admits every 8.x and upstream only states minimums;
     * a ceiling with no source would refuse working versions for nothing.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.2', 'max' => null];
    }
}
