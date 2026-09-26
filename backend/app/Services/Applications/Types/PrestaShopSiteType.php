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
    /**
     * PrestaShop 8.x, from its own `install_version.php` (7.2.5 – 8.1).
     */
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
     * The union of the releases the installer can choose from, measured from
     * each release's own `install/install_version.php` on 2026-09-26:
     *
     *   9.1.5 (Classic, current stable)  PHP 8.1   – 8.5
     *   8.2.8 (open source, last 8.x)    PHP 7.2.5 – 8.1
     *
     * The installer picks the newest stable release whose range holds the
     * site's PHP ({@see PrestaShopInstaller::release()}), so an 8.4 shop
     * gets 9.1 and a 7.4 shop gets 8.2 — this range is simply what at least
     * one of them accepts.
     *
     * Until 2026-09-26 it was 7.2 – 8.1, because the panel followed the old
     * `channel.xml` feed, which still names 8.2.1 as current and carries no
     * 9.x. That ceiling was not pedantry: on 2026-09-08 a shop installed on
     * PHP 8.5 died in `ProxyCacheWarmer->warmUp()` — PrestaShop 8 vendors the
     * monolithic `symfony/symfony`. It is safe to raise now only because the
     * release is chosen by PHP version: 8.5 gets PrestaShop 9, never 8.
     *
     * The floor stays theirs (7.2.5, compared here as 7.2), not something
     * tidier: refusing a version PrestaShop supports is not this rule's job.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '7.2', 'max' => '8.5'];
    }

    /**
     * What this shop's own release runs on, recorded by the installer.
     *
     * A shop with no record was installed before releases were chosen by PHP,
     * from the old feed — so it is PrestaShop 8, and 8's ceiling of 8.1 still
     * holds. Widening the type's range must not let the PHP screen move one of
     * those onto 8.4: that is the 2026-09-08 crash again, on a live shop.
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
            // Timezones come from the server, not a static list: the value has
            // to be one this machine actually has, and Timezones already reads
            // it from timedatectl for exactly that reason.
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
            'shop_name' => ['required', 'string', 'max:64'],
            'admin_first_name' => ['required', 'string', 'max:64'],
            'admin_last_name' => ['required', 'string', 'max:64'],
            'admin_email' => ['required', 'email', 'max:255'],
            // PrestaShop's own minimum is 8.
            'admin_password' => ['required', 'string', 'min:10'],
            'country' => ['nullable', 'string', Rule::in(FieldOptions::countries())],
            'language' => ['nullable', 'string', Rule::in(FieldOptions::languages())],
            // Not the `timezone` rule: it validates against PHP's list, which
            // omits the 78 backward-compatible zones that timedatectl offers
            // and a fresh Ubuntu box can actually be set to. Validating
            // against the same list the field offers is the only way the two
            // cannot disagree.
            'timezone' => ['nullable', Rule::in(app(Timezones::class)->identifiers())],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * PrestaShop's own nginx configuration, as published in its developer
     * documentation (devdocs.prestashop-project.org, "Configure Nginx"):
     * source folders — `var/` holds the logs, cache and sessions — vendor
     * code inside modules, template and log files, and no PHP from the two
     * upload folders. On Apache the same comes from the `.htaccess` it ships
     * in each of those folders.
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
        ];
    }
}
