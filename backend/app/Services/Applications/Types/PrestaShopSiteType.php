<?php

namespace App\Services\Applications\Types;

use App\Models\Application;
use App\Services\Server\Applications\Installers\PrestaShopInstaller;
use App\Services\Timezones;
use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * PrestaShop — e-commerce.
 */
class PrestaShopSiteType extends AbstractSiteType
{
    /** PrestaShop 8.x, from its own `install_version.php` (7.2.5 – 8.1). */
    public const PRESTASHOP_8_RANGE = ['min' => '7.2', 'max' => '8.1'];

    public function name(): string
    {
        return 'prestashop';
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
        return 'ecommerce';
    }

    public function icon(): string
    {
        return 'shopping-cart';
    }

    public function popular(): bool
    {
        return true;
    }

    /**
     * The union of the PHP ranges of the releases the installer can choose from:
     *
     *   9.1.5 (Classic, current stable)  PHP 8.1   – 8.5
     *   8.2.8 (open source, last 8.x)    PHP 7.2.5 – 8.1
     *
     * The installer picks the newest stable release whose range holds the site's PHP
     * ({@see PrestaShopInstaller::release()}); this range is what at least one accepts.
     * PrestaShop 8 crashes on PHP 8.5 (`ProxyCacheWarmer->warmUp()`), so the ceiling is only safe
     * because 8.5 always gets PrestaShop 9. The floor is PrestaShop's own (7.2.5, compared as 7.2).
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.2', 'max' => '8.5'];
    }

    /**
     * What this shop's own release runs on, recorded by the installer.
     *
     * A shop with no record predates PHP-based release choice, so it is PrestaShop 8 and keeps
     * 8's ceiling of 8.1: widening the type's range must not move it onto a PHP it cannot run.
     */
    public function supportedPhpRangeFor(Application $application): ?array
    {
        $recorded = $application->settings['php_range'] ?? null;

        if (is_array($recorded) && is_string($recorded['min'] ?? null) && is_string($recorded['max'] ?? null)) {
            return ['min' => $recorded['min'], 'max' => $recorded['max']];
        }

        return self::PRESTASHOP_8_RANGE;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('shop_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.shop_name')]),
            $this->field('admin_first_name', 'text', required: true, extra: ['default' => 'Admin']),
            $this->field('admin_last_name', 'text', required: true, extra: ['default' => 'User']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('country', 'select', advanced: true, extra: [
                'default' => 'gb',
                'options' => FieldOptions::countryOptions(FieldOptions::countries()),
            ]),
            $this->field('language', 'select', advanced: true, extra: [
                'default' => 'en',
                'options' => FieldOptions::languageOptions(FieldOptions::languages()),
            ]),
            // From the server, not a static list: the value must be one this machine has.
            $this->field('timezone', 'select', advanced: true, extra: [
                'default' => 'UTC',
                'options' => FieldOptions::asOptions(app(Timezones::class)->identifiers()),
            ]),
            $this->field('table_prefix', 'text', advanced: true, extra: ['default' => 'ps_']),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            // `< > = { }` fail PrestaShop's own isGenericName, and its CLI parser drops any value
            // holding `=`, `|` or a backtick, so the shop would silently be named "PrestaShop".
            'shop_name' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match(PrestaShopInstaller::SHOP_NAME_FORBIDDEN, $value) === 1) {
                    $fail(__('errors/application.shop_name_characters'));
                }
            }],
            'admin_first_name' => ['required', 'string', 'max:64'],
            'admin_last_name' => ['required', 'string', 'max:64'],
            'admin_email' => ['required', 'email', 'max:255'],
            // PrestaShop's own minimum is 8.
            'admin_password' => ['required', 'string', 'min:10'],
            'country' => ['nullable', 'string', Rule::in(FieldOptions::countries())],
            'language' => ['nullable', 'string', Rule::in(FieldOptions::languages())],
            // Not the `timezone` rule: PHP's list omits backward-compatible zones that timedatectl
            // offers. Validate against the same list the field offers.
            'timezone' => ['nullable', Rule::in(app(Timezones::class)->identifiers())],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * Paths PrestaShop's own nginx configuration denies (devdocs.prestashop-project.org,
     * "Configure Nginx"): source folders (`var/` holds logs, cache, sessions), module vendor code,
     * template and log files, and PHP in the upload folders. Apache gets the same from its
     * shipped `.htaccess` files.
     *
     * @return array<int, string>
     */
    public function deniedPaths(): array
    {
        return [
            '^/(app|bin|cache|classes|config|controllers|docs|localization|override|src|tests|tools|translations|var|vendor)/',
            '^/modules/.*/vendor/',
            '\\.(log|tpl|twig|sass|yml)$',
            '^/(img|upload)/.*\\.php',
            // Not in the nginx sample, but in PrestaShop's root `.htaccess`: it lists the exact
            // version of every dependency.
            '^/composer\\.lock$',
        ];
    }

    /**
     * The back office is its own Symfony application in `admin<random>/`, and its `.htaccess`
     * sends every path there to that folder's `index.php`. Without this, nginx and OpenLiteSpeed
     * hand `/admin…/login` to the shop's front controller, which answers 404. The folder name is
     * random and not recorded, so it is matched; PrestaShop 9's `admin-api` (hyphen) is left alone.
     *
     * @return array<int, array{directory: string, script: string}>
     */
    public function subdirectoryFrontControllers(): array
    {
        return [['directory' => 'admin[a-z0-9]+', 'script' => 'index.php']];
    }
}
