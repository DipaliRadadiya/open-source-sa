<?php

namespace App\Services\Applications\Types;

/**
 * Vaultwarden — a Bitwarden-compatible password server.
 *
 * `/data` holds the SQLite database, the attachments and the RSA keypair that
 * signs every session token. Losing it is losing every vault, so it is one
 * volume and not a bind mount into the site directory.
 *
 * The first person to open the site registers; there is no admin to hand over.
 * The admin panel is a separate thing gated on `ADMIN_TOKEN`, deliberately not
 * set here — an admin panel nobody asked for, reachable with a token in a
 * compose file, is a worse default than not having one.
 */
class VaultwardenSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'vaultwarden';
    }

    public function category(): string
    {
        return 'security';
    }

    public function icon(): string
    {
        return 'vaultwarden';
    }

    public function containerPort(): int
    {
        return 80;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/data',
        ];
    }
}
