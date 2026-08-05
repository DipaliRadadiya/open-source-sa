<?php

namespace App\Services\Applications\Types;

use App\Contracts\SiteType;

/**
 * Shared defaults and field helpers, so a concrete site type is mostly just
 * its field list.
 */
abstract class AbstractSiteType implements SiteType
{
    /**
     * Derived rather than listed, so adding a type does not mean copying
     * sixteen names into it. Only the differences are worth writing down, and
     * a type overrides this when it has one.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        // True of every site: it has a name, it is served, it is logged, it
        // can be backed up, cloned and protected. No `app_setting` — there is
        // no dedicated Settings screen; enable/disable lives on the Dashboard.
        $features = [
            'app_dashboard',
            'app_domain',
            'app_log',
            'app_backup',
            'app_file',
            'app_security',
            'app_firewall',
            'app_bot_blocker',
            'app_fail2ban',
            'app_clone',
        ];

        // Deployment is a git screen. A one-click install has no repository, no
        // branch and no commit history — the screen would have nothing on it.
        if ($this->method() === 'git') {
            $features[] = 'app_deployment';
        }

        // PHP settings edit a pool and a php.ini. A static site has neither,
        // and a Node site's runtime is not PHP.
        if ($this->servingProfile() === 'php') {
            $features[] = 'app_php';
        }

        // Something running in the background: a Node process, or the queue
        // workers a deployed application tends to need. A marketplace PHP app
        // manages its own scheduling and has nothing here to supervise.
        if ($this->servingProfile() === 'node' || $this->method() === 'git') {
            $features[] = 'app_worker';
        }

        // An environment file the panel owns. Git and Node applications read
        // one; a marketplace PHP app keeps its configuration in its own file
        // (wp-config.php and friends), which is the application's business,
        // not ours to present as ".env".
        if ($this->method() === 'git' || $this->servingProfile() === 'node') {
            $features[] = 'app_environment';
        }

        return $features;
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Fields every application has, whatever its type.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function commonFields(): array
    {
        return [
            $this->field('name', 'text', required: true),
            $this->field('domain', 'domain', required: true),
            $this->field('system_user_id', 'select', required: true, extra: ['source' => 'system_users']),
        ];
    }

    /**
     * Runtime + document-root fields for anything served by PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function phpFields(): array
    {
        return [
            $this->field('php_version', 'select', extra: ['source' => 'php_versions', 'default' => '8.4']),
            $this->field('web_root', 'text', advanced: true, extra: ['default' => $this->defaultWebRoot()]),
        ];
    }

    /**
     * Runtime + port fields for anything that runs its own process.
     *
     * The port is optional and advanced: the panel allocates a free one, and
     * on a server the user owns they may still want to choose. There is no
     * start-command field — a one-click application has exactly one right
     * answer and its installer writes it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function nodeFields(): array
    {
        return [
            $this->field('node_version', 'select', extra: ['source' => 'node_versions']),
            $this->field('app_port', 'number', advanced: true, extra: [
                'help' => __('application.help.app_port'),
            ]),
        ];
    }

    public function defaultWebRoot(): string
    {
        return '/';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function field(string $name, string $type, bool $required = false, bool $advanced = false, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            // Localized here so the frontend never holds a field-label list.
            'label' => __("application.fields.{$name}"),
            'type' => $type,
            'required' => $required,
            'advanced' => $advanced,
        ], $extra);
    }
}
