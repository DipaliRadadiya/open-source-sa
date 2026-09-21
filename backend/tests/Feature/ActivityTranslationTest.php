<?php

/*
| Every activity event the code logs must have a sentence to show for it.
|
| 🔴 Found on a real server, 2026-09-21: the ionCube events had no translation
| in any locale, so the activity log rendered the raw key —
|
|     php.ioncube_installed  ->  "activity.php.ioncube_installed"
|
| Laravel returns the key verbatim when it cannot find a translation, so this
| fails silently: the feature works, the log is written, and only a person
| reading the screen can tell. Four events had been shipping that way.
|
| It also reaches further than the sentence. `GET /admin/activity-log/filters`
| builds its dropdowns from THESE keys rather than from a DISTINCT over the
| table, so an event with no key is absent from the filter list as well — the
| rows exist and cannot be filtered to.
*/

it('has an English sentence for every event the code logs', function () {
    $logged = loggedActivityKeys();

    expect($logged)->not->toBeEmpty('no ActivityLogger calls found — is the pattern still right?');

    $translations = require lang_path('en/activity.php');

    $missing = array_values(array_filter(
        $logged,
        fn (string $key): bool => ! array_key_exists($key, $translations),
    ));

    expect($missing)->toBe([], 'activity events with no sentence: '.implode(', ', $missing));
});

it('keeps every locale in step with English', function () {
    // A key present in English and missing elsewhere renders the raw key for
    // exactly the users least able to report it.
    $english = array_keys(require lang_path('en/activity.php'));

    foreach (['es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $keys = array_keys(require lang_path("{$locale}/activity.php"));

        expect(array_values(array_diff($english, $keys)))
            ->toBe([], "{$locale} is missing activity keys");
    }
});

/**
 * Every `'<type>.<action>'` passed to ActivityLogger::log() in app/.
 *
 * Read from the source rather than from a list someone maintains, because a
 * maintained list is the thing that drifts — which is how this was missed.
 *
 * @return array<int, string>
 */
function loggedActivityKeys(): array
{
    $keys = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        // ->log('php.ioncube_installed', …) — the first argument only, and
        // only literals: a key built from a variable cannot be checked here
        // and is deliberately out of scope rather than guessed at.
        // The closing quote must be followed by a comma or a closing paren —
        // i.e. the whole first argument is a literal. Two call sites build the
        // key by concatenation (`'application.worker_'.$action`), and matching
        // those yielded the half-key `application.worker_`, which exists in no
        // locale and never will. A prefix is not an event.
        preg_match_all(
            "/->log\(\s*'([a-z0-9_]+\.[a-z0-9_]+)'\s*[,)]/i",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        $keys = [...$keys, ...$matches[1]];
    }

    return array_values(array_unique($keys));
}
