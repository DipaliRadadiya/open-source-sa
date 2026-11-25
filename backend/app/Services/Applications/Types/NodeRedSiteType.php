<?php

namespace App\Services\Applications\Types;

/**
 * Node-RED — flow-based automation.
 *
 * The credentials are required on purpose: Node-RED ships with no authentication and a flow runs
 * arbitrary code as the site user, so an unauthenticated one on a public domain is a remote shell.
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

    /**
     * The editor authenticates with `Authorization: Bearer <token>` (from /auth/token) on every
     * admin API call, and the token cannot travel any other way. Panel Basic Auth in front would
     * make nginx and Node-RED each reject the other's header, so the editor would be unusable.
     */
    public function authorizationHeaderAuth(): bool
    {
        return true;
    }

    /**
     * For the release the installer pins: major 4 (`server.installers.nodered.version`), whose
     * `engines.node` is `>=18.5`. Not read from the catalog: the registry does not resolve a bare
     * major. Node-RED 5 needs 22.9, so moving the pin means moving this (a test checks).
     */
    public function supportedNodeRange(): ?array
    {
        return ['min' => '18.5', 'max' => null];
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
