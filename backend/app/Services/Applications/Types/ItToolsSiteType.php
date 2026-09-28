<?php

namespace App\Services\Applications\Types;

/**
 * IT-Tools — a collection of developer utilities.
 *
 * **No volumes at all**, and that is correct rather than an omission: everything
 * runs in the browser and the container serves static assets. It is the only app
 * here with nothing to persist, which is why the shared template makes the
 * volume block conditional.
 */
class ItToolsSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'ittools';
    }

    public function category(): string
    {
        return 'development';
    }

    public function icon(): string
    {
        return 'ittools';
    }

    public function containerPort(): int
    {
        return 80;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [];
    }
}
