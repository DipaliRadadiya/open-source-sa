<?php

namespace App\Services\Applications\Types;

/**
 * An empty PHP site — the directory and vhost are made, the user uploads
 * their own files. The simplest PHP path, and the fallback for anything the
 * marketplace does not cover.
 */
class PhpSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'php';
    }

    public function method(): string
    {
        return 'custom';
    }

    public function servingProfile(): string
    {
        return 'php';
    }

    public function category(): string
    {
        return 'developer';
    }

    public function icon(): string
    {
        return 'code';
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), $this->phpFields());
    }
}
