<?php

namespace App\Services\Applications\Types;

/**
 * Gitea — Git hosting with issues and pull requests.
 *
 * SQLite in `/data`, which is Gitea's own default and is genuinely fine for the
 * size of instance a panel creates. A Postgres variant would be a second card,
 * not a hidden option.
 *
 * **SSH is not published.** Gitea listens on 22 inside the container for git
 * over SSH, and the host's 22 belongs to the server. Clone over HTTPS works
 * without it; exposing it would need a port the operator chose, which is a
 * decision for the Container card rather than a default.
 */
class GiteaSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'gitea';
    }

    public function category(): string
    {
        return 'development';
    }

    public function icon(): string
    {
        return 'gitea';
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
        return 'GITEA__server__ROOT_URL';
    }
}
