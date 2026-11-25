<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a PHP version the chosen application will not run on.
 *
 * The sibling of {@see SupportedNodeVersion}. The PHP field offers every installed version and
 * defaults to the newest, which an app may not support (e.g. PrestaShop 8.2.1 on PHP 8.5). Caught
 * at the form because by install time the user, directory, vhost and database already exist.
 *
 * **The ceiling is compared as major.minor, unlike the Node rule:** PHP's support series is the
 * minor (8.1 and 8.5 share a major), so comparing majors would admit the version that broke.
 */
class SupportedPhpVersion implements ValidationRule
{
    /**
     * @param  string|null  $fallback  The version an empty field resolves to.
     */
    public function __construct(
        private ?string $min,
        private ?string $max,
        private string $typeTitle,
        private ?string $fallback = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $version = (string) $value;

        // An empty field resolves to `server.default_php_version` (the newest PHP, which a ceiling
        // exists to exclude), so check the fallback: that is what will actually run.
        if (blank($version)) {
            if (blank($this->fallback)) {
                return;
            }

            if (! $this->withinRange((string) $this->fallback)) {
                $fail($this->defaultMessage());
            }

            return;
        }

        if (! $this->withinRange($version)) {
            $fail($this->message());
        }
    }

    /**
     * Compared as `major.minor`, for the reason the class docblock gives.
     */
    private function withinRange(string $version): bool
    {
        return self::within($this->min, $this->max, $version);
    }

    /**
     * Public so the catalog and the form use the same comparison.
     */
    public static function within(?string $min, ?string $max, string $version): bool
    {
        if ($min !== null && version_compare($version, $min, '<')) {
            return false;
        }

        return ! ($max !== null && version_compare($version, $max, '>'));
    }

    /**
     * Names the supported range, so the user knows what to pick instead.
     */
    private function message(): string
    {
        return __('validation.php_version_unsupported', [
            'type' => $this->typeTitle,
            'range' => $this->range(),
        ]);
    }

    /**
     * The empty-field case, worded separately: it names the default version the user would get.
     */
    private function defaultMessage(): string
    {
        return __('validation.php_version_default_unsupported', [
            'type' => $this->typeTitle,
            'range' => $this->range(),
            'default' => (string) $this->fallback,
        ]);
    }

    private function range(): string
    {
        return self::describe($this->min, $this->max);
    }

    /**
     * The range as written for users, shared with the catalog's unavailable reason.
     */
    public static function describe(?string $min, ?string $max): string
    {
        return match (true) {
            $min !== null && $max !== null => "{$min} – {$max}",
            $min !== null => "{$min}+",
            default => "≤ {$max}",
        };
    }
}
