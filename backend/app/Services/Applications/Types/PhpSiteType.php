<?php

namespace App\Services\Applications\Types;

/**
 * An empty PHP site — the directory and vhost are made, the user uploads
 * their own files. The simplest PHP path, and the fallback for anything the
 * marketplace does not cover.
 */
class PhpSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'php';
    }

    public function method(): string
    {
        return 'custom';
    }

    public function servingProfile(): string
    {
        return 'php';
    }

    public function category(): string
    {
        return 'developer';
    }

    /**
     * A worker is "keep this command running as this user" — nothing about it
     * is framework-specific. Someone who uploaded their own PHP and wants a
     * long-running script supervised has exactly the same need as a Laravel
     * site, and cron is the wrong tool for a process that should never stop.
     *
     * Not `app_environment` though: a hand-rolled site has no framework that
     * reads a `.env`, so offering one would be inventing a convention on the
     * user's behalf.
     */
    public function features(): array
    {
        return [...parent::features(), 'app_worker'];
    }

    public function icon(): string
    {
        return 'code';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->phpFields());
    }
}
