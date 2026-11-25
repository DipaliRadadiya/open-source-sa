<?php

namespace App\Services\Applications\Types;

use App\Services\Runtime\AppPackageCatalog;

/**
 * n8n, workflow automation.
 *
 * Fair-code under the Sustainable Use License, not open source: self-hosting for your own use is
 * allowed, redistributing it as part of a hosted offering is not.
 */
class N8nSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'n8n';
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'node';
    }

    public function category(): string
    {
        return 'automation';
    }

    public function icon(): string
    {
        return 'n8n';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * SQLite by default, inside the site's own directory.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * n8n documents a 2 GB RAM minimum. Under the 512M default the unit is killed on startup, hits
     * its start limit and the site answers 502.
     */
    public function defaultMemoryMax(): ?string
    {
        return '2G';
    }

    /**
     * What the release being installed says it runs on.
     *
     * Read from the package, since the installer resolves `latest` and a hard-coded range goes
     * stale. The literal is only the fallback (no egress or unrefreshed catalog), kept narrow on
     * purpose: too strict costs a dropdown entry, too loose gives a site that will not boot.
     */
    public function supportedNodeRange(): ?array
    {
        return app(AppPackageCatalog::class)->nodeRange($this->npmPackage())
            ?? ['min' => '24', 'max' => null];
    }

    /**
     * The task-runner broker, which n8n binds on 127.0.0.1:5679 by default. A second n8n finds it
     * taken and exits on start.
     */
    public function needsInternalPort(): bool
    {
        return true;
    }

    /**
     * Encrypts every saved credential. See N8nInstaller's class note.
     */
    public function lockedEnvironmentKeys(): array
    {
        return ['N8N_ENCRYPTION_KEY'];
    }

    /**
     * Written by N8nInstaller from the site's own record (ports, data folder, loopback address).
     * Edited by hand, each one breaks the site at the next restart instead of on save.
     */
    public function panelManagedEnvironmentKeys(): array
    {
        return ['N8N_PORT', 'N8N_USER_FOLDER', 'N8N_LISTEN_ADDRESS', 'N8N_RUNNERS_BROKER_PORT'];
    }

    /**
     * The npm package this site type installs; the catalog asks the registry about it.
     */
    public function npmPackage(): ?string
    {
        return 'n8n';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            // The panel creates the first administrator itself, after the application starts.
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->nodeFields());
    }

    /**
     * n8n refuses a password without an upper-case letter and a number, so the
     * rule says so here instead of failing the install at the last step.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10', 'max:64', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ];
    }
}
