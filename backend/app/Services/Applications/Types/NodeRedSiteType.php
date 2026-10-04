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

    /**
     * The editor authenticates with `Authorization: Bearer <token>`, obtained
     * from /auth/token and sent on every admin API call.
     *
     * Verified against a live site with the panel's password protection on:
     *
     *   Authorization: Basic  -> nginx passes, Node-RED answers 401 Bearer
     *   Authorization: Bearer -> nginx answers 401 Basic
     *
     * and the token cannot travel any other way -- ?access_token= and a cookie
     * were both refused. So /settings, /flows and /nodes are unreachable while
     * Basic Auth is in front, which makes the editor unusable.
     */
    public function authorizationHeaderAuth(): bool
    {
        return true;
    }

    /**
     * Bug #16: the type declared no range, so every installed Node was
     * offered, however old.
     *
     * For the release the installer pins — major 4 (`server.installers.
     * nodered.version`), whose `engines.node` is `>=18.5` (4.1.15, read from
     * the npm registry 2026-10-04). Not the catalog: the registry's
     * single-version endpoint does not resolve a bare major ("version not
     * found: 4"), so a refresh would store nothing. Node-RED 5 needs 22.9 —
     * moving the pin means moving this, and a test says so.
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
