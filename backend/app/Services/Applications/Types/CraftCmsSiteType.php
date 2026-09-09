<?php

namespace App\Services\Applications\Types;

use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * Craft CMS — content management for developers.
 */
class CraftCmsSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'craftcms';
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
        return 'cms';
    }

    public function icon(): string
    {
        return 'craftcms';
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * A floor and no ceiling, the same shape as Statamic's.
     *
     * `craftcms/cms` 5.x requires `php ^8.2` and 4.x requires `^8.0.2`
     * (repo.packagist.org, read 2026-09-09), and the installer runs
     * `composer create-project craftcms/craft` unpinned.
     *
     * That combination is why the missing range was worse than a failed
     * install: on PHP 8.0 or 8.1 Composer does not stop, it **resolves
     * backwards** and installs Craft 4 — a different major, with a different
     * upgrade path, on a site the panel reports as a Craft site. A refusal
     * naming the version is the better answer than silently getting something
     * else.
     *
     * No ceiling because Craft states none, and inventing one would refuse a
     * version that works the day PHP ships it.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.2', 'max' => null];
    }

    /**
     * Craft keeps its source beside a small public directory. Serving the root
     * instead would publish that source, `.env` included.
     */
    public function defaultWebRoot(): string
    {
        return '/web';
    }

    /**
     * And it is the only one that works. `CraftCmsInstaller` builds the project
     * into the directory *above* the web root and Craft puts its front
     * controller in `web/`, so any other value produces a vhost pointed at a
     * directory Craft never creates — and, when that value is `/`, one that
     * holds `.env` with the database password in it.
     */
    public function fixedWebRoot(): ?string
    {
        return $this->defaultWebRoot();
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('site_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_name')]),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('language', 'select', advanced: true, extra: [
                'default' => 'en-US',
                'options' => FieldOptions::localeOptions(FieldOptions::hyphenLocales()),
            ]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Craft's own minimum is 6; ours is stricter.
            'admin_password' => ['required', 'string', 'min:10'],
            'language' => ['nullable', 'string', Rule::in(FieldOptions::hyphenLocales())],
        ];
    }

    /**
     * Craft reads a `.env` at the project root — unusual for a marketplace
     * install, and the reason this overrides the default. Database credentials
     * and the security key live there, so hiding the screen would mean the
     * only way to change them is a file manager.
     */
    public function features(): array
    {
        // Workers for the same reason: Craft ships a queue (`craft
        // queue/listen`), and a site that can configure a queue in .env but
        // cannot run one is only half a panel.
        return [...parent::features(), 'app_environment', 'app_worker'];
    }
}
