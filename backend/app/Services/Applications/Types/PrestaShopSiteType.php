<?php

namespace App\Services\Applications\Types;

use App\Services\Timezones;
use App\Support\FieldOptions;
use Illuminate\Validation\Rule;

/**
 * PrestaShop — e-commerce.
 */
class PrestaShopSiteType extends AbstractSiteType
{
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

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('shop_name', 'text', required: true),
            $this->field('admin_first_name', 'text', required: true, extra: ['default' => 'Admin']),
            $this->field('admin_last_name', 'text', required: true, extra: ['default' => 'User']),
            $this->field('admin_email', 'email', required: true),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('country', 'select', advanced: true, extra: [
                'default' => 'gb',
                'options' => FieldOptions::asOptions(FieldOptions::countries()),
            ]),
            $this->field('language', 'select', advanced: true, extra: [
                'default' => 'en',
                'options' => FieldOptions::asOptions(FieldOptions::languages()),
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
}
