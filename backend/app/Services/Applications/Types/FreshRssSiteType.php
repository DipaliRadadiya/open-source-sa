<?php

namespace App\Services\Applications\Types;

/**
 * FreshRSS — a self-hosted feed reader.
 *
 * SQLite under the data directory, and the first visitor runs the installer and
 * creates the administrator.
 */
class FreshRssSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'freshrss';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'freshrss';
    }

    public function containerPort(): int
    {
        return 80;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/var/www/FreshRSS/data',
        ];
    }
}
