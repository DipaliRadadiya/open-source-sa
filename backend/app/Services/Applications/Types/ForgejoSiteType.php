<?php

namespace App\Services\Applications\Types;

/**
 * Forgejo — Git hosting; a community-governed fork of Gitea.
 *
 * Offered alongside Gitea rather than instead of it: they are not
 * interchangeable once a site exists, and somebody choosing one is choosing its
 * governance as much as its features.
 *
 * Same shape as Gitea, including SSH being unpublished for the same reason.
 */
class ForgejoSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'forgejo';
    }

    public function category(): string
    {
        return 'development';
    }

    public function icon(): string
    {
        return 'forgejo';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/data',
        ];
    }

    /**
     * The app's own base URL. Wrong, and every clone command, webhook and email
     * it produces points at the wrong host — which reads as a broken install
     * rather than a stale setting.
     */
    public function urlEnvKey(): ?string
    {
        return 'FORGEJO__server__ROOT_URL';
    }
}
