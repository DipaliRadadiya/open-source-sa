<?php

namespace App\Support;

/**
 * The brand colour, safe to put into a stylesheet.
 *
 * `branding.primary_color` comes from the environment and lands inside a
 * `<style>` block — on the panel's own pages and on the pages it writes into
 * customers' document roots. Anything but a plain hex colour is refused, so a
 * value with a `;` or a `}` in it cannot add rules (or close the style tag).
 */
final class BrandColor
{
    /** The default when the configured value is missing or not a hex colour. */
    public const DEFAULT = '#62C690';

    public static function value(): string
    {
        $color = trim((string) config('branding.primary_color'));

        return preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $color) === 1 ? $color : self::DEFAULT;
    }
}
