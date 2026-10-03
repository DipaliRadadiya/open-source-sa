<?php

namespace App\Services\Server\Applications;

use App\Models\Application;

/**
 * Every spelling of a WordPress site's URL, mapped onto another site's.
 *
 * One literal `https://staging.example.com` is not enough, and the gaps
 * are the ones that leave a site half-migrated:
 *
 *  - **Scheme mismatch.** `Application::url()` builds from the site's own
 *    scheme, so a staging site on http and a production site on https
 *    produce two strings that never match each other. That single case
 *    misses *everything*.
 *  - **Escaped slashes.** The block editor and any JSON-encoded option
 *    store `https:\/\/host`. wp-cli does not unescape before matching, so
 *    a plain replace walks straight past post content.
 *  - **Protocol-relative.** `//host` appears in enqueued asset URLs.
 *  - **Bare domain.** Email templates, plugin settings and CSV exports
 *    keep the host with no scheme at all.
 *
 * Ordered longest to shortest so the bare-domain pass runs last and
 * cannot corrupt a string an earlier, more specific pass already fixed.
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

        // Both schemes for each side, so a staging-on-http / production-on-
        // https pair is still caught.
        foreach (['https://', 'http://'] as $scheme) {
            $variants[] = [$scheme.$fromHost, $to->url()];
            $variants[] = [str_replace('/', '\\/', $scheme).$fromHost, str_replace('/', '\\/', $to->url())];
        }

        $variants[] = ['//'.$fromHost, '//'.$toHost];
        $variants[] = ['\\/\\/'.$fromHost, '\\/\\/'.$toHost];

        // Last, and only the host: anything with a scheme is already done.
        //
        // As plain text only when the new host does not contain the old one.
        // A copy is usually a subdomain of its source — example.com to
        // staging.example.com — and then every address the passes above
        // already rewrote still contains `example.com`, so a plain pass turns
        // it into staging.staging.example.com (bug #92, found while sharing
        // this list with clone and staging create; push goes the other way
        // and never hit it). There the host is matched as a whole name: not
        // after a letter, digit, dot or hyphen, and not before one.
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
