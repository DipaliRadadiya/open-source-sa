<?php

namespace App\Services\Applications\Types;

use App\Services\Server\Applications\AdminerSso;

/**
 * Adminer: a database client in one PHP file, for the engines phpMyAdmin cannot open
 * (PostgreSQL).
 *
 * Like phpMyAdmin it is a tool, not a site: no admin account and no database of its own. Users
 * sign in with a database user's credentials, normally via the one-click link
 * ({@see AdminerSso}).
 */
class AdminerSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'adminer';
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
     * A tool, not a site (as phpMyAdmin): it holds no content, so backup and clone are
     * meaningless and reinstalling is the recovery path.
     *
     * Password protection stays: an exposed Adminer is a login page for every database.
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), [
            'app_backup',
            'app_clone',
        ]));
    }

    /**
     * Adminer's `composer.json` declares `"php": ">=7.4"`. No ceiling: none is published, and an
     * unsourced one refuses working versions.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.4', 'max' => null];
    }
}
