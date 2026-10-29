<?php

namespace App\Services\Applications\Types;

use App\Services\Server\Applications\AdminerSso;

/**
 * Adminer — a database client in one PHP file, for the engines phpMyAdmin
 * cannot open.
 *
 * phpMyAdmin speaks MySQL and MariaDB only, so until this a PostgreSQL user had
 * no browser client at all. Adminer speaks both. Like phpMyAdmin it is a tool,
 * not a site: no admin account, no database of its own, nothing to name.
 * Whoever opens it signs in with a database user's credentials — normally via
 * the panel's one-click link ({@see AdminerSso}).
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
     * A tool, not a site — the same reasoning as phpMyAdmin: it holds no
     * content of its own, so backing it up or cloning it are screens about
     * nothing, and reinstalling is the honest recovery path.
     *
     * Password protection stays: an exposed Adminer is a login page for every
     * database on the box.
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), [
            'app_backup',
            'app_clone',
        ]));
    }

    /**
     * Adminer 6.1.2, measured 2026-10-14, and 6.2.0 again on 2026-10-29:
     * `composer.json` declares `"php": ">=7.4"`. No ceiling — none is published, and a ceiling with no
     * source behind it refuses working versions for nothing (the phpMyAdmin
     * reasoning, 2026-09-18).
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.4', 'max' => null];
    }
}
