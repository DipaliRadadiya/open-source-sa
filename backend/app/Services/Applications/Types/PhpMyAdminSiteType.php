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
}
