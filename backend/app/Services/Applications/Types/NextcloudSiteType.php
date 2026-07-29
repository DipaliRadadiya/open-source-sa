<?php

namespace App\Services\Applications\Types;

/**
 * Nextcloud — self-hosted file sync and share.
 */
class NextcloudSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'nextcloud';
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'php';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function popular(): bool
    {
        return true;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'admin_user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Nextcloud's own wizard rates password strength and asks for
            // "strong"; this is the floor the API will accept.
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }
}
