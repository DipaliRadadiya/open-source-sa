<?php

namespace App\Enums;

/**
 * Where a site's first domain came from.
 *
 * Not the same axis as {@see DomainType}, which says what a name *does*
 * (primary, alias, redirect). This says whether the user brought the name or
 * the panel handed them one to get started with.
 *
 * It matters for exactly one reason: a temporary `<name>.<ip>.nip.io` hostname
 * must never be put on a Let's Encrypt certificate. nip.io is not on the
 * Public Suffix List, so every certificate issued for it anywhere in the world
 * counts against a single shared weekly limit — a panel that requested one per
 * new site would exhaust a quota it does not own.
 */
enum DomainOrigin: string
{
    /** A wildcard-DNS hostname the panel offered, resolving to this server. */
    case Temporary = 'temp';

    /** A real domain the user owns and pointed here themselves. */
    case Custom = 'custom';

    public function isTemporary(): bool
    {
        return $this === self::Temporary;
    }
}
