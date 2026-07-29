<?php

namespace App\Services\Applications\Types;

use App\Contracts\SiteType;

/**
 * Shared defaults and field helpers, so a concrete site type is mostly just
 * its field list.
 */
abstract class AbstractSiteType implements SiteType
{
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
    protected function phpFields(string $webRoot = '/'): array
    {
        return [
            $this->field('php_version', 'select', extra: ['source' => 'php_versions', 'default' => '8.4']),
            $this->field('web_root', 'text', advanced: true, extra: ['default' => $webRoot]),
        ];
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
