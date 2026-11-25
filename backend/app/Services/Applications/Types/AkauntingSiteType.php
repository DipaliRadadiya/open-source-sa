<?php

namespace App\Services\Applications\Types;

use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * Akaunting — accounting and invoicing.
 */
class AkauntingSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'akaunting';
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
        return 'business';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('company_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.company_name')]),
            $this->field('company_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.company_email')]),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('locale', 'select', advanced: true, extra: [
                'default' => 'en-GB',
                'options' => FieldOptions::localeOptions(FieldOptions::hyphenLocales()),
            ]),
            // Default prefix so a shared database stays recognisable; an empty value is still
            // passed to the installer untouched.
            $this->field('table_prefix', 'text', advanced: true, extra: [
                'default' => 'akt_',
                'help' => __('application.help.table_prefix_optional'),
            ]),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
            'locale' => ['nullable', 'string', Rule::in(FieldOptions::hyphenLocales())],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * Akaunting declares `"php": "^8.1"` and stops its own install on a lower version, leaving a
     * half-provisioned site. No ceiling: it names none.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.1', 'max' => null];
    }

    /**
     * Akaunting is served from its application root, so `storage/`, `config/`, `.env` and the
     * rest sit under the web root. Mirrors its own `.htaccess`; under `modules/` and `vendor/`
     * it lists code extensions (no lookahead) so OpenLiteSpeed can apply it too.
     *
     * @return array<int, string>
     */
    public function deniedPaths(): array
    {
        return [
            '^/(app|bootstrap|config|database|overrides|resources|routes|storage|tests)/',
            '^/artisan$',
            '^/composer\\.(json|lock)$',
            '\\.(env|log)$',
            '^/(modules|vendor)/.*\\.(php[0-9]?|phtml|phar|inc|json|lock|md|txt|xml|ya?ml|dist|sh|twig|stub|neon)$',
        ];
    }

    /**
     * Akaunting reads a `.env` beside its code, the same as Craft and Statamic.
     *
     * @return array<int, string>
     */
    /**
     * Laravel's encryption key: a new one signs everybody out and leaves
     * anything the application encrypted with the old one unreadable.
     */
    public function lockedEnvironmentKeys(): array
    {
        return ['APP_KEY'];
    }

    public function features(): array
    {
        return [...parent::features(), 'app_environment'];
    }
}
