<?php

namespace App\Services\Applications\Types;

use App\Models\Application;

/**
 * Chatwoot — customer support and shared inbox.
 *
 * **Four services, where every other app here is one or two.** Chatwoot is a
 * Rails app that cannot run without a background worker: `sidekiq` processes
 * every outgoing email, webhook and automation, so a site with only the web
 * container looks fine and silently does none of that work. Its Postgres is
 * `pgvector`, not `postgres` — Chatwoot's schema declares the `vector` extension
 * and plain Postgres refuses the migration. Redis is not a cache here: Sidekiq's
 * queue lives in it, as does the installation-onboarding flag below.
 *
 * **Nothing migrates the schema on its own.** Upstream's `rails.sh` entrypoint
 * waits for Postgres, runs `bundle install` and execs the command — there is no
 * migration step anywhere in the image. A first boot without one is a Rails app
 * answering 500 against an empty database, so the web service runs
 * `db:chatwoot_prepare` ahead of the server. It is idempotent, which is also what
 * makes it the upgrade path: a newer image migrates on its next boot.
 *
 * It is NOT a separate one-shot service, which was the first design: every
 * service in a generated file carries `restart: unless-stopped`, and a container
 * that exits 0 under that policy restarts forever.
 *
 * **The first administrator is created by the panel**, in the installer's
 * `afterStart()` — see `firstRunClaim()`. Production seeds create no user at all;
 * they set a Redis flag that opens `/installation/onboarding` to whoever asks
 * first. On a public URL from the moment it starts, that is the n8n problem, and
 * it gets the n8n answer.
 */
class ChatwootSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'chatwoot';
    }

    public function category(): string
    {
        return 'communication';
    }

    public function icon(): string
    {
        return 'chatwoot';
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.chatwoot';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    /**
     * Chatwoot builds invitation links, password resets and the widget's own
     * script tag from this, so a wrong value invites people to a host that does
     * not serve them.
     */
    public function urlEnvKey(): ?string
    {
        return 'FRONTEND_URL';
    }

    /**
     * Rails plus a Sidekiq worker plus pgvector plus Redis, and this figure is
     * only the web container — the worker and the datastores carry their own
     * ceilings in the template.
     *
     * By a distance the heaviest app in this catalog. Inside the 512m server
     * default Rails is killed during boot, which presents as a 502 with nothing
     * about memory anywhere in the panel — the same way Metabase and NocoDB
     * failed before they declared floors.
     */
    public function defaultMemoryLimit(): ?string
    {
        return '2g';
    }

    /**
     * Three, and the two datastores are the whole product.
     *
     * `/app/storage` is ActiveStorage: every file an agent or a customer attaches
     * to a conversation. It is a subdirectory of the app, not a mount over it, so
     * it hides nothing the image ships.
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'storage' => '/app/storage',
            'db' => '/var/lib/postgresql/data',
            'redis' => '/data',
        ];
    }

    /**
     * `SECRET_KEY_BASE` signs every session cookie: regenerating it logs everyone
     * out, so it is stored per site and never recovered from the rendered file.
     *
     * `ADMIN_PASSWORD` is not read by Chatwoot at all — it is the password the
     * panel uses to claim the instance in `firstRunClaim()`, and it is here so it
     * is generated, stored and shown on the first-run card exactly once.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['SECRET_KEY_BASE', 'POSTGRES_PASSWORD', 'REDIS_PASSWORD', 'ADMIN_PASSWORD'];
    }

    /**
     * Chatwoot never reads `ADMIN_PASSWORD` — the panel posts it to the
     * onboarding endpoint and shows it once. In the compose file it would be the
     * administrator's password sitting in a file the File Manager can open, with
     * nothing reading it.
     *
     * @return list<string>
     */
    public function panelOnlySecrets(): array
    {
        return ['ADMIN_PASSWORD'];
    }

    /**
     * The size fields, plus who the first administrator is.
     *
     * The password is generated rather than asked for: it is shown once on the
     * first-run card and Chatwoot can change it afterwards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return array_merge(parent::fields(), [
            $this->field('admin_email', 'email', required: true, extra: [
                'placeholder' => __('application.placeholders.admin_email'),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'admin_email' => ['required', 'email', 'max:255'],
        ]);
    }

    /**
     * Claim the instance before a stranger can.
     *
     * `POST /installation/onboarding` is open only while the Redis flag the
     * production seeds set is present, and the controller deletes that flag once
     * it has built the account — so this both creates the administrator and
     * closes the door, and a second call is a redirect rather than a second
     * account. `ApplicationController` skips `verify_authenticity_token`, so a
     * form post with no browser session is accepted.
     *
     * `subscribe_to_updates` is deliberately absent: present and non-blank, the
     * controller registers the installation with ChatwootHub — someone else's
     * server learning about this one, from a panel whose point is that it does
     * not do that.
     *
     * @return array{path: string, fields: array<string, string>}|null
     */
    public function firstRunClaim(Application $application): ?array
    {
        $settings = $application->installSettings();
        $secrets = (array) ($application->docker_secrets ?? []);

        $email = (string) ($settings['admin_email'] ?? '');
        $password = (string) ($secrets['ADMIN_PASSWORD'] ?? '');

        if ($email === '' || $password === '') {
            return null;
        }

        return [
            'path' => '/installation/onboarding',
            'fields' => [
                'user[name]' => 'Admin',
                'user[company]' => $application->name,
                'user[email]' => $email,
                'user[password]' => $password,
            ],
        ];
    }
}
