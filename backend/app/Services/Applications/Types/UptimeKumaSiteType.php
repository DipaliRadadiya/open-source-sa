<?php

namespace App\Services\Applications\Types;

/**
 * Uptime Kuma — uptime monitoring.
 *
 * No admin fields: Uptime Kuma has no setup CLI, so the first visitor creates
 * the administrator. The tagline says so, because a site that is reachable and
 * unclaimed is not a state to leave someone guessing about.
 */
class UptimeKumaSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'uptimekuma';
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
        return 'monitoring';
    }

    public function icon(): string
    {
        return 'uptimekuma';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * A floor and no ceiling, from upstream's own `package.json`.
     *
     * Added with the move off the pinned `2.0.0`, because the move changes the
     * answer: 2.0.0 declared `18 || >= 20.4.0`, and 2.5.3 declares `>= 20.4.0`
     * — Node 18 was still acceptable to the version the panel used to install
     * and is not acceptable to the version it now installs. Nothing had ever
     * asserted a range here, so a site would have been created on Node 18 and
     * built against a package that no longer supports it.
     *
     * No ceiling because upstream states none.
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '20.4', 'max' => null];
    }

    /**
     * SQLite, inside its own directory.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            // The panel creates the first administrator itself, after the
            // application starts. Left to the first-run page, it was whoever
            // opened the URL first.
            $this->field('admin_username', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->nodeFields());
    }

    /**
     * Uptime Kuma refuses a password it rates "too weak", so the rule asks for
     * mixed case and a number instead of failing the install at the last step.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'admin_password' => ['required', 'string', 'min:10', 'max:64', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ];
    }
}
