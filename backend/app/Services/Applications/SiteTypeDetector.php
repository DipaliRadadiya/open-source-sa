<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\ServerOps;

/**
 * What is actually installed in a site's directory, read off the disk.
 *
 * Extracted from `ApplicationDiscoverer`, which inferred a site type for
 * brownfield sync and kept the signature table private. A second caller now
 * needs the same answer — a user who created a Custom PHP site, installed
 * WordPress into it by hand, and wants the panel to notice — and the one
 * thing that must not happen is a second copy of the table. Every privilege
 * bug this panel has had came from exactly that: a list maintained in two
 * places, edited in one.
 *
 * Same detect-don't-trust rule as {@see FrameworkDetector},
 * which answers the neighbouring question (which *framework* is deployed, for
 * `.env` handling). The two are deliberately separate: a framework is what the
 * code is written in, a site type is which screens the panel offers, and
 * `wordpress` is one of those and not the other.
 */
class SiteTypeDetector
{
    /**
     * file => [site_type, serving_profile, confidence]
     *
     * Ordered most specific first: a Laravel repository has a `package.json`
     * too, and a WordPress install has PHP files everywhere. The first match
     * that is actually distinguishing wins.
     *
     * Moved here verbatim from the sync discoverer. Note that not every entry
     * is something the panel may *offer* to relabel a site as — `artisan`
     * resolves to `git`, which describes a checkout the panel owns and cannot
     * be conjured from files on disk. That filtering is the caller's job; see
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
     * The entry point the sync discoverer uses, because at discovery time
     * there is no application yet — only a document root parsed out of a
     * vhost file.
     */
    public function detectAt(string $documentRoot, array $context = []): SiteTypeVerdict
    {
        $context = $context + ['feature' => 'application', 'op' => 'detect_site_type'];

        foreach (self::SIGNATURES as $file => [$siteType, $profile, $confidence]) {
            // `test -f` rather than reading the directory: a listing of a site
            // with 40,000 files to answer one yes/no question is not a trade
            // worth making on a box that is also serving traffic.
            //
            // `probe()` rather than `run()`, which is what the discoverer used
            // and a small wart it carried: exit 1 here means "not installed",
            // the expected answer for five of the six signatures on any given
            // site, and logging each one as a failed operation made the
            // server-ops log unreadable for the one case that was real.
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
     * More than the document root, because the most valuable signature is the
     * one most likely to sit outside it. Moving `wp-config.php` one level
     * above the web root is long-standing WordPress hardening advice and
     * common in real installs — so a detector that only ever looked at the
     * document root would fail on precisely the sites whose owners know what
     * they are doing.
     *
     * Candidate order follows {@see FrameworkDetector::candidateRoots()}
     * for the same reason it gives: a non-root web root may describe a
     * conventional project layout, with the marker beside `public/` rather
     * than inside it.
     *
     * The first directory with a match wins, and the fallback is the
     * document root's own verdict — not a merge. Two directories disagreeing
     * is not a case to average; it is a case to report the most specific
     * finding for.
     */
    public function detect(Application $application): SiteTypeVerdict
    {
        $context = ['feature' => 'application', 'op' => 'detect_site_type', 'application' => $application->id];

        $best = null;

        foreach ($this->candidateRoots($application) as $root) {
            $verdict = $this->detectAt($root, $context);

            // An unknown verdict carries no evidence, so it cannot beat a
            // directory we have not looked in yet.
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
