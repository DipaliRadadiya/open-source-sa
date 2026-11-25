<?php

namespace App\Services\Applications;

/**
 * What the panel believes is installed in a directory, and how sure it is.
 *
 * Confidence decides whether the panel says anything to the user at all: a `wp-config.php`
 * means WordPress, "there are PHP files here" means almost nothing and should stay quiet.
 *
 * `matched` is the file the verdict rests on, so the user can check the suggestion.
 */
readonly class SiteTypeVerdict
{
    public function __construct(
        public string $siteType,
        public string $servingProfile,
        public int $confidence,
        public ?string $matched = null,
        /**
         * The directory the match was found in, not always the document root.
         * See SiteTypeDetector::detect().
         */
        public ?string $root = null,
    ) {}

    /**
     * Nothing recognisable here.
     *
     * `php` rather than `static`: serving a PHP application as plain files publishes its
     * source, while the reverse mistake only costs a redundant handler.
     */
    public static function unknown(): self
    {
        return new self('php', 'php', 10);
    }

    /**
     * The shape the sync discoverer stores on a sync item and an adopted application, kept
     * here so the two callers cannot drift on the key names.
     *
     * @return array{site_type: string, serving_profile: string, confidence: int, matched: string|null}
     */
    public function toDiscoveryAttributes(): array
    {
        return [
            'site_type' => $this->siteType,
            'serving_profile' => $this->servingProfile,
            'confidence' => $this->confidence,
            'matched' => $this->matched,
        ];
    }
}
