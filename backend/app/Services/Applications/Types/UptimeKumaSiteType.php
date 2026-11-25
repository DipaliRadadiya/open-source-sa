<?php

namespace App\Services\Applications\Types;

/**
 * Uptime Kuma — uptime monitoring.
 *
 * No admin fields: Uptime Kuma has no setup CLI, so the first visitor creates the administrator.
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
     * A floor and no ceiling, from upstream's own `package.json` (`>= 20.4.0`, no upper bound).
     * Node 18 is no longer supported by the installed version.
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '20.4', 'max' => null];
    }

    /** SQLite, inside its own directory. */
    public function needsDatabase(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            // The panel creates the first administrator itself after the app starts; left to
            // the first-run page it would be whoever opens the URL first.
            $this->field('admin_username', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->nodeFields());
    }

    /**
     * Uptime Kuma refuses a password it rates "too weak", so require mixed case and a number
     * instead of failing the install at the last step.
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
