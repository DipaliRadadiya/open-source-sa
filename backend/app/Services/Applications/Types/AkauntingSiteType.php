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
            // `akt_`, an operator decision (2026-09-03). Akaunting is a
            // Laravel application and Laravel does not prefix tables, so there
            // was no convention to inherit — but a shared database with no
            // prefix at all is the case this field exists for, and a prefix
            // someone can recognise is worth more than fidelity to a framework
            // default nobody sees. AkauntingInstaller still passes an empty
            // --db-prefix through untouched if the box is cleared.
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
}
