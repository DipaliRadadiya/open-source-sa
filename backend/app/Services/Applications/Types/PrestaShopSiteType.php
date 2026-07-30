<?php

namespace App\Services\Applications\Types;

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
            $this->field('country', 'text', advanced: true, extra: ['default' => 'gb']),
            $this->field('language', 'text', advanced: true, extra: ['default' => 'en']),
            $this->field('timezone', 'text', advanced: true, extra: ['default' => 'UTC']),
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
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[a-z]{2}$/'],
            'language' => ['nullable', 'string', 'size:2', 'regex:/^[a-z]{2}$/'],
            'timezone' => ['nullable', 'timezone'],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }
}
