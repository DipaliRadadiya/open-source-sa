<?php

namespace App\Services\Applications\Types;

/**
 * NodeBB — forum software.
 *
 * The one application in the catalog that needs **MongoDB** rather than MySQL.
 * It says `needsDatabase`, and its installer names the engine it can actually
 * use, so a server with only MySQL fails before anything is downloaded rather
 * than inside NodeBB's own setup.
 */
class NodeBbSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'nodebb';
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
        return 'community';
    }

    public function icon(): string
    {
        return 'nodebb';
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * A forum plus its asset build. The build is the demanding half — it runs
     * its webpack targets in parallel and is the step that dies first on a
     * small box, taking the templates with it.
     */
    public function defaultMemoryMax(): ?string
    {
        return '1G';
    }

    /**
     * NodeBB v4.x — the branch `config/server.php` installs — requires Node 22
     * or greater, per its own README. No ceiling is published.
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '22', 'max' => null];
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_username', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->nodeFields());
    }

    public function rules(): array
    {
        return [
            // NodeBB's own constraint, checked here so the failure is a form
            // error rather than a setup that runs for a minute and then stops.
            'admin_username' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[A-Za-z0-9._\- ]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }
}
