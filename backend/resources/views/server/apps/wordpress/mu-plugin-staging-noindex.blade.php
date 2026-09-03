{!! '<?php' !!}
/**
 * Plugin Name: Staging Search Engine Block
 * Plugin URI:  https://serveravatar.com/docs/staging
 * Description: Stops search engines indexing this staging site, so a copy of a live site cannot compete with the original in search results. Added automatically by your hosting control panel when this staging site was created. This is not malware and was not added by an attacker — see the notes below. It is never copied to your live site.
 * Version:     1.0.0
 * Author:      Your hosting control panel
 * License:     GPL-2.0-or-later
 *
 * -------------------------------------------------------------------------
 * IF YOU FOUND THIS FILE AND ARE WORRIED IT IS A HACK — IT IS NOT.
 * -------------------------------------------------------------------------
 *
 * WHAT IT IS
 *   Your hosting control panel wrote this file when it created the staging
 *   copy of your website.
 *
 * WHY IT EXISTS
 *   A staging site is a full copy of your live site on a different address.
 *   To a search engine that looks like the same content published twice.
 *   Left alone, the copy gets crawled and indexed, splits the ranking signals
 *   with the real site, and can end up outranking it — visitors then land on
 *   a test site that may be half-finished or serving stale prices.
 *
 * WHAT IT DOES
 *   Sends `X-Robots-Tag: noindex, nofollow` on every response and forces
 *   WordPress's own robots directives to the same. Nothing is collected,
 *   nothing is transmitted anywhere, and no content is changed. The whole
 *   plugin is the few lines below.
 *
 * WHY A FILE AND NOT JUST THE SETTING
 *   The panel also unticks "Search engine visibility" (`blog_public`) on this
 *   site. That is a database value, and the panel's own staging tools replace
 *   this database. A file cannot be undone by a database import, and the
 *   header it sends applies even when a physical robots.txt on disk overrides
 *   the one WordPress would generate.
 *
 * WHERE IT RUNS
 *   On this staging site only. It is excluded from the push to your live
 *   site, so it can never make your real site invisible to search engines.
 *   Your live site's own visibility setting is read before a push and written
 *   back afterwards, so pushing staging over it does not change it either.
 *
 * HOW TO REMOVE IT
 *   Delete this file and tick "Search engine visibility" under Settings →
 *   Reading. Only do that if you genuinely want this copy indexed alongside
 *   your live site.
 */

// Belt and braces: the header applies to every response including ones that
// never render a <head>, such as feeds, REST replies and attachments.
add_action('send_headers', function () {
    if (! headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
});

// WordPress 5.7+ assembles the robots meta tag through this filter.
add_filter('wp_robots', function (array $robots): array {
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    unset($robots['index'], $robots['follow']);

    return $robots;
}, PHP_INT_MAX);
