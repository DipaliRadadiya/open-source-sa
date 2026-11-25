<?php

namespace App\Services\Server\Applications;

use App\Models\Application;

/**
 * Every spelling of a WordPress site's URL, mapped onto another site's.
 *
 * One literal URL is not enough; these gaps leave a site half-migrated:
 *
 *  - **Scheme mismatch.** `Application::url()` uses the site's own scheme, so http staging and
 *    https production never match.
 *  - **Escaped slashes.** The block editor and JSON options store `https:\/\/host`; wp-cli does
 *    not unescape before matching.
 *  - **Protocol-relative.** `//host` appears in enqueued asset URLs.
 *  - **Bare domain.** Emails, plugin settings and CSV exports keep the host without a scheme.
 *
 * Ordered longest to shortest so the bare-domain pass runs last and cannot corrupt a string an
 * earlier pass already fixed.
 */
final class WordPressUrlVariants
{
    public const REGEX_DELIMITER = '#';

    /**
     * @return array<int, array{0: string, 1: string, 2?: bool}> [search, replace, regex] in order;
     *                                                           `regex` true means `search` is a
     *                                                           pattern for wp-cli's `--regex`.
     */
    public static function between(Application $from, Application $to): array
    {
        $fromHost = $from->domain;
        $toHost = $to->domain;

        $variants = [];

        // Both schemes, so an http/https pair is still caught.
        foreach (['https://', 'http://'] as $scheme) {
            $variants[] = [$scheme.$fromHost, $to->url()];
            $variants[] = [str_replace('/', '\\/', $scheme).$fromHost, str_replace('/', '\\/', $to->url())];
        }

        $variants[] = ['//'.$fromHost, '//'.$toHost];
        $variants[] = ['\\/\\/'.$fromHost, '\\/\\/'.$toHost];

        // Last, and only the host: anything with a scheme is already done.
        //
        // Plain text only when the new host does not contain the old one. For a subdomain copy
        // (example.com -> staging.example.com) a plain pass would turn already rewritten
        // addresses into staging.staging.example.com, so the host is matched as a whole name.
        $variants[] = str_contains($toHost, $fromHost)
            ? [self::wholeHost($fromHost), $toHost, true]
            : [$fromHost, $toHost];

        return $variants;
    }

    /**
     * A wp-cli `--regex` pattern for the host as a whole name. Delimited by
     * `#` (see REGEX_DELIMITER), which a host name cannot contain.
     */
    private static function wholeHost(string $host): string
    {
        return '(?<![A-Za-z0-9.-])'.preg_quote($host, self::REGEX_DELIMITER).'(?![A-Za-z0-9-]|\\.[A-Za-z0-9])';
    }
}
