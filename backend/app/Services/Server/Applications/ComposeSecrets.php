<?php

namespace App\Services\Server\Applications;

/**
 * Masks a site's generated secrets inside its compose file.
 *
 * A recipe writes the passwords it generated straight into the file, so the
 * file shows them to anyone allowed to read it. Viewing the file and seeing
 * the passwords are two different grants, and this is how the first is kept
 * from implying the second.
 */
class ComposeSecrets
{
    /**
     * Shown in place of a secret. Plain letters and underscores, so it reads the
     * same in any YAML position and cannot be mistaken for a real value.
     */
    public const PLACEHOLDER = '__hidden_by_panel__';

    /**
     * Below this a "secret" is too short to mask by text: replacing a value
     * like `app` would also mangle every image name and path containing it.
     * Every generated secret is far longer.
     */
    private const MIN_LENGTH = 8;

    /**
     * @param  array<string, mixed>  $secrets
     * @return array{0: string, 1: bool} the masked file, and whether anything was masked
     */
    public static function redact(string $compose, array $secrets): array
    {
        $values = array_filter(
            array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_values($secrets)),
            fn (string $value) => strlen($value) >= self::MIN_LENGTH,
        );

        // Each value also as the template's one filter writes it:
        // `{{ secret.APP_KEY|base64 }}` (RecipeTemplate). Found live on a
        // BookStack install, whose APP_KEY reached a view-only user that way.
        $values = array_merge($values, array_map('base64_encode', $values));

        // Longest first, so a secret that contains another is masked whole.
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $masked = str_replace($values, self::PLACEHOLDER, $compose);

        return [$masked, $masked !== $compose];
    }

    public static function containsPlaceholder(string $compose): bool
    {
        return str_contains($compose, self::PLACEHOLDER);
    }
}
