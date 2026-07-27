<?php

namespace App\Contracts;

/**
 * One group of server settings (general / security / updates / redis). Reads
 * live from the OS (detect-don't-trust) and applies changes via managed,
 * non-destructive drop-ins — so it stays correct on a migrated server.
 */
interface SettingGroup
{
    /** Stable group key (also the i18n key + route segment). */
    public function key(): string;

    /** True when this group applies to the box (e.g. redis installed). */
    public function available(): bool;

    /**
     * Current values, read live.
     *
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * Apply validated values. Throws a translated exception on failure.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void;
}
