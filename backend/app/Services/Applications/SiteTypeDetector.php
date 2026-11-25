<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\ServerOps;

/**
 * What is actually installed in a site's directory, read off the disk.
 *
 * Shared by the brownfield sync discoverer and the "re-detect site type" flow, so the signature
 * table lives in one place.
 *
 * Same detect-don't-trust rule as {@see FrameworkDetector}, which answers a different question:
 * a framework is what the code is written in, a site type is which screens the panel offers.
 */
class SiteTypeDetector
{
    /**
     * file => [site_type, serving_profile, confidence]
     *
     * Ordered most specific first; the first distinguishing match wins.
     *
     * Not every entry may be offered as a relabel: `artisan` resolves to `git`, which a checkout
     * the panel owns cannot be conjured from files. The caller filters; see
     * `server.site_type_detection.suggestable`.
     */
    private const SIGNATURES = [
        'wp-config.php' => ['wordpress', 'php', 95],
        'artisan' => ['git', 'php', 80],
        'bin/magento' => ['php', 'php', 70],
        'configuration.php' => ['joomla', 'php', 60],
        'index.php' => ['php', 'php', 40],
        'index.html' => ['static', 'static', 40],
    ];

    public function __construct(
        private ServerOps $serverOps,
    ) {}

    /**
     * Inspect one directory.
     *
     * Used by the sync discoverer, where there is no application yet, only a document root.
     */
    public function detectAt(string $documentRoot, array $context = []): SiteTypeVerdict
    {
        $context = $context + ['feature' => 'application', 'op' => 'detect_site_type'];

        foreach (self::SIGNATURES as $file => [$siteType, $profile, $confidence]) {
            // `test -f`, not a directory listing: a site can have tens of thousands of files.
            // `probe()`, not `run()`: exit 1 means "not installed", the normal answer for most
            // signatures, and must not be logged as a failed operation.
            $result = $this->serverOps->probe(
                ['test', '-f', rtrim($documentRoot, '/').'/'.$file],
                $context,
                timeout: 15,
            );

            if ($result->ok) {
                return new SiteTypeVerdict($siteType, $profile, $confidence, $file, $documentRoot);
            }
        }

        return SiteTypeVerdict::unknown();
    }

    /**
     * Inspect an application, trying the directories its marker could be in.
     *
     * Not only the document root: `wp-config.php` is often moved one level above it.
     * Candidate order follows {@see FrameworkDetector::candidateRoots()}: the marker may sit
     * beside `public/` rather than inside it.
     *
     * The highest-confidence verdict wins; verdicts are never merged.
     */
    public function detect(Application $application): SiteTypeVerdict
    {
        $context = ['feature' => 'application', 'op' => 'detect_site_type', 'application' => $application->id];

        $best = null;

        foreach ($this->candidateRoots($application) as $root) {
            $verdict = $this->detectAt($root, $context);

            // An unknown verdict carries no evidence and cannot beat a later directory.
            if ($verdict->matched === null) {
                $best ??= $verdict;

                continue;
            }

            if ($best === null || $best->matched === null || $verdict->confidence > $best->confidence) {
                $best = $verdict;
            }
        }

        return $best ?? SiteTypeVerdict::unknown();
    }

    /**
     * @return array<int, string>
     */
    private function candidateRoots(Application $application): array
    {
        $documentRoot = $application->documentRoot();
        $roots = [$documentRoot, $application->codePath()];

        if (trim((string) $application->web_root, '/') !== '') {
            $roots[] = dirname($documentRoot);
        }

        return array_values(array_unique(array_filter($roots)));
    }
}
