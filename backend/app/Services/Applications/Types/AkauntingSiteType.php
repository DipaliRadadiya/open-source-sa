<?php

namespace App\Services\Applications\Types;

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
            $this->field('company_name', 'text', required: true),
            $this->field('company_email', 'email', required: true),
            $this->field('admin_email', 'email', required: true),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('locale', 'text', advanced: true, extra: ['default' => 'en-GB']),
            $this->field('table_prefix', 'text', advanced: true),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:10'],
            'locale' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z-]+$/'],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }
}
