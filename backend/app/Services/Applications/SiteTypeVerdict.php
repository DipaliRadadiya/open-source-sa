<?php

namespace App\Services\Applications;

/**
 * What the panel believes is installed in a directory, and how sure it is.
 *
 * Confidence is not decoration — it is the field that decides whether the
 * panel says anything to the user at all. A `wp-config.php` means WordPress
 * and little else; "there are PHP files here" means almost nothing, and a
 * screen that offers to relabel a site on that basis is worse than one that
 * stays quiet.
 *
 * `matched` is the file the verdict rests on. It is carried because a
 * suggestion the user cannot check is a suggestion the user has to take on
 * faith: "this looks like WordPress" invites an argument, "this looks like
 * WordPress because wp-config.php is in your web root" ends one.
 */
readonly class SiteTypeVerdict
{
    public function __construct(
        public string $siteType,
        public string $servingProfile,
        public int $confidence,
        public ?string $matched = null,
        /**
         * The directory the match was found in — not always the document
         * root. See SiteTypeDetector::detect().
         */
        public ?string $root = null,
    ) {}

    /**
     * Nothing recognisable here.
     *
     * `php` rather than `static`, because serving a PHP application as a
     * directory of files publishes its source, and the reverse mistake only
     * costs a redundant handler. Inherited from the sync discoverer this was
     * extracted from, deliberately unchanged.
     */
    public static function unknown(): self
    {
        return new self('php', 'php', 10);
    }

    /**
     * The shape the sync discoverer stores on a sync item and an adopted
     * application. Kept as a method here so that the two callers cannot drift
     * about what the keys are called.
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
