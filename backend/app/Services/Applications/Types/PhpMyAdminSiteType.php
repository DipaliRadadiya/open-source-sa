<?php

namespace App\Services\Applications\Types;

/**
 * phpMyAdmin — a database client, not a site.
 *
 * The simplest card in the catalog: no admin account to create, no database
 * of its own, nothing to name. Whoever opens it signs in with their own
 * database credentials, so the create form is just the common fields.
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
     * It reads the databases already on the server. One of its own would sit
     * empty, and the standing account that came with it would be a credential
     * nobody asked for.
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
     * A tool, not a site. It holds no content of its own — it reads the
     * databases that are already on the server — so backing it up, cloning it
     * or password-listing it are all screens about nothing. Reinstalling is
     * the honest recovery path.
     *
     * Password protection stays: an exposed phpMyAdmin is a login page for
     * every database on the box, and a second lock in front of it is the one
     * hardening step that matters most here.
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), [
            'app_backup',
            'app_clone',
        ]));
    }
}
