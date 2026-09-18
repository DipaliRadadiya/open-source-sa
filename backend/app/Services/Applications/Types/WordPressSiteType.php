<?php

namespace App\Services\Applications\Types;

use App\Contracts\CloneStrategy;
use App\Contracts\StagingStrategy;
use App\Services\Server\Applications\Cloning\WordPressCloneStrategy;
use App\Services\Server\Applications\Staging\WordPressStagingStrategy;
use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * WordPress — the reference one-click type, and the one with the most
 * type-specific fields. If the generic form renderer can draw this, it can
 * draw every other marketplace app.
 */
class WordPressSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'wordpress';
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
        return 'wordpress';
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
            $this->field('site_title', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_title')]),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            // Offered pre-filled with a strong value so the simple path never
            // invites a weak password.
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('site_language', 'select', advanced: true, extra: [
                'default' => 'en_US',
                'options' => FieldOptions::localeOptions(FieldOptions::wordpressLocales()),
            ]),
            $this->field('timezone', 'text', advanced: true, extra: [
                'help' => __('application.help.timezone'),
                'placeholder' => __('application.placeholders.timezone'),
            ]),
            $this->field('table_prefix', 'text', advanced: true, extra: ['default' => 'wp_']),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_title' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
            'site_language' => ['nullable', 'string', Rule::in(FieldOptions::wordpressLocales())],
            // Accepts any valid PHP timezone string. wp option update validates it.
            'timezone' => ['nullable', 'string', 'max:64'],
            // Goes into SQL identifiers, so keep it strictly boring.
            'table_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/'],
        ];
    }

    /**
     * Staging is a per-type recipe, not a generic file copy: pushing a
     * WordPress staging site back needs URL rewriting inside serialised data,
     * wp-cron disabled and outbound mail trapped. That recipe exists for
     * WordPress and nothing else yet, so nothing else offers the screen.
     *
     * No `app_environment`: WordPress keeps its configuration in
     * wp-config.php, which is the application's file, not an env file the
     * panel owns. Presenting it as one would invite edits that the next
     * WordPress update overwrites.
     */
    public function features(): array
    {
        // `app_clone` is re-added, not inherited: the base list withholds it
        // from every database-backed type because copying the files alone
        // leaves a site pointing at the original's database. WordPress is the
        // one type with a `cloneStrategy()`, so it is the one type that gets
        // the screen back.
        // `app_magic_login` is WordPress's alone, and deliberately not on the
        // base list. Signing in without a password needs somewhere to put the
        // loader and a notion of "administrator" to sign in as; nothing else
        // here has both. Being the only holder is also what makes the routes
        // 404 everywhere else without a single site-type check in the
        // controller.
        return [...parent::features(), 'app_clone', 'app_staging', 'app_magic_login'];
    }

    public function stagingStrategy(): ?StagingStrategy
    {
        return app(WordPressStagingStrategy::class);
    }

    public function cloneStrategy(): ?CloneStrategy
    {
        return app(WordPressCloneStrategy::class);
    }

    /**
     * WordPress, measured 2026-09-18.
     *
     * `wp-includes/version.php` carries `$required_php_version = '7.4'` — the
     * value WordPress itself enforces before it will run.
     *
     * No ceiling, and that is WordPress's own position: it supports new PHP
     * releases rather than declaring an upper bound. Recording a maximum here
     * would refuse versions that work.
     *
     * The floor is deliberately WordPress's rather than a tidier modern one. A
     * range exists to stop installs that cannot work, not to express a
     * preference — and 7.4 is what the application will actually start on.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.4', 'max' => null];
    }
}
