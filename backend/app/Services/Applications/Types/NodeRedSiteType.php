<?php

namespace App\Services\Applications\Types;

/**
 * Node-RED — flow-based automation.
 *
 * The credentials are required rather than optional, and that is deliberate:
 * Node-RED ships with no authentication, and a flow can run arbitrary code as
 * the site user. An unauthenticated Node-RED on a public domain is a remote
 * shell, so the panel will not create one.
 */
class NodeRedSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'nodered';
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
        return 'nodered';
    }

    public function needsDatabase(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('admin_username', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
        ], $this->nodeFields());
    }

    public function rules(): array
    {
        return [
            'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'admin_password' => ['required', 'string', 'min:10'],
        ];
    }
}
