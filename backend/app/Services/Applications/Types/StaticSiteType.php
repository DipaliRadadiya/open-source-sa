<?php

namespace App\Services\Applications\Types;

/**
 * Plain HTML/CSS/JS. No runtime at all, so it is available on every server
 * regardless of what is installed.
 */
class StaticSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'static';
    }

    public function method(): string
    {
        return 'custom';
    }

    public function servingProfile(): string
    {
        return 'static';
    }

    public function category(): string
    {
        return 'developer';
    }

    /**
     * The plain starting point for anyone bringing their own code, so it
     * sits under Popular next to Git (Dipali, 2026-11-05). It stays in the
     * Development category too: Popular is a filter, not a category.
     */
    public function popular(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'file-code';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('web_root', 'text', advanced: true, extra: ['default' => '/']),
        ]);
    }
}
