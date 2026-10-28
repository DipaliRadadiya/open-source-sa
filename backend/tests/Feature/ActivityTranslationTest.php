<?php

use App\Actions\Server\Application\RunApplicationProcess;
use App\Actions\Server\Service\RunServiceAction;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

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

it('has a sentence for every event a log call can choose, not only the first literal', function () {
    // `$wasEnabled !== $enabled ? ($enabled ? 'firewall.rule_enabled' : 'firewall.rule_disabled') : …`
    // and `match` arms name several events in one call; the check above saw
    // only one, so a missing sentence in the second arm reached the screen as
    // `activity.x.y`.
    $translations = require lang_path('en/activity.php');
    $missing = [];

    foreach (activityLogCallArguments() as [$file, $argument]) {
        preg_match_all("/'([a-z0-9_]+\.[a-z0-9_]+)'(?!\s*\.)/", $argument, $literals);

        foreach ($literals[1] as $key) {
            if (! array_key_exists($key, $translations)) {
                $missing[] = "{$key} ({$file})";
            }
        }

        // A key built from a prefix (`'application.process_'.$action`): at
        // least one event has to begin with it, or every value it can take is
        // a raw key on the screen.
        preg_match_all("/'([a-z0-9_]+\.[a-z0-9_]*_)'\s*\./", $argument, $prefixes);

        foreach ($prefixes[1] as $prefix) {
            if (! collect(array_keys($translations))->contains(fn (string $key) => str_starts_with($key, $prefix))) {
                $missing[] = "{$prefix}* ({$file})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('knows every log call whose event it cannot read, so a new one is looked at', function () {
    // A key from a variable or a method cannot be checked from the source.
    // Each one here was read by hand: every value it can take has a sentence
    // (the families are checked one by one below). A new one fails this test
    // until someone does the same.
    $reviewed = [
        'Actions/Auth/ChangeUserPassword.php',          // user.password_changed / user.password_reset_by_admin
        'Actions/Server/DiskCleaner/RunCleanupAction.php', // verb(): disk_cleaner.{cleaned,clean_failed,auto_cleaned,auto_clean_failed}
        'Actions/Server/Service/RunServiceAction.php',  // 'service.'.VERBS[...]
    ];
    $unread = [];

    foreach (activityLogCallArguments() as [$file, $argument]) {
        $literal = preg_match("/'[a-z0-9_]+\.[a-z0-9_]+'/", $argument) === 1
            || preg_match("/'[a-z0-9_]+\.[a-z0-9_]*_'\s*\./", $argument) === 1;

        if (! $literal && ! in_array($file, $reviewed, true)) {
            $unread[] = "{$file}: ".trim($argument);
        }
    }

    expect($unread)->toBe([]);
});

it('has a sentence for every value of the events built at run time', function () {
    $translations = require lang_path('en/activity.php');
    $expected = [
        ...array_map(fn ($v) => 'service.'.$v, (new ReflectionClassConstant(RunServiceAction::class, 'VERBS'))->getValue()),
        ...array_map(fn ($m) => 'application.staging_pulled_'.$m, ['files', 'database', 'full']),
        ...array_map(fn ($m) => 'application.staging_pushed_'.$m, ['files', 'database', 'full']),
        ...array_map(fn ($a) => 'application.process_'.$a, RunApplicationProcess::ACTIONS),
        ...array_map(fn ($a) => 'application.worker_'.$a, ['start', 'stop', 'restart']), // WorkerController::control()
        'disk_cleaner.cleaned', 'disk_cleaner.clean_failed', 'disk_cleaner.auto_cleaned', 'disk_cleaner.auto_clean_failed',
        'user.password_changed', 'user.password_reset_by_admin',
    ];

    expect(array_values(array_diff($expected, array_keys($translations))))->toBe([]);
});

it('uses the same placeholders in every language', function () {
    // A translation that drops `:name` shows less; one that spells it
    // `:nom` shows the placeholder itself.
    $placeholders = fn (string $text) => collect(preg_match_all('/(?<![\w:]):([a-z_]+)/', $text, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    $english = require lang_path('en/activity.php');
    $wrong = [];

    foreach (['es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        foreach (require lang_path("{$locale}/activity.php") as $key => $text) {
            if (isset($english[$key]) && $placeholders($english[$key]) !== $placeholders($text)) {
                $wrong[] = "{$locale}: {$key}";
            }
        }
    }

    expect($wrong)->toBe([]);
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

it('keeps the change labels and words in step with English', function (string $file) {
    $flat = fn (string $locale) => array_keys(Arr::dot(require lang_path("{$locale}/{$file}.php")));
    $english = $flat('en');

    foreach (['es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        expect(array_values(array_diff($english, $flat($locale))))->toBe([], "{$locale}/{$file}.php is missing keys");
    }
})->with(['activity_fields', 'activity_values']);

describe('what the activity screen shows for a row', function () {
    $shown = fn (string $type, string $action, array $properties = []) => (new ActivityLogResource(
        ActivityLog::create(['type' => $type, 'action' => $action, 'properties' => $properties])
    ))->toArray(request());

    it('words an event with no sentence instead of showing its key', function () use ($shown) {
        Log::spy();

        $row = $shown('mystery_box', 'did_a_thing');

        expect($row['description'])->toBe('Mystery box: did a thing')
            ->not->toContain('activity.');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context['key'] === 'activity.mystery_box.did_a_thing');
    });

    it('shows a dash, not the placeholder, for a value the row never recorded', function () use ($shown) {
        expect($shown('application', 'deleted', ['name' => 'Shop'])['description'])->toBe('Deleted application Shop (—)');
    });

    it('leaves a row with no change list exactly as before', function () use ($shown) {
        $row = $shown('application', 'deleted', ['name' => 'Shop', 'site_type' => 'php']);

        expect($row['description'])->toBe('Deleted application Shop (php)')
            ->and($row['changes'])->toBeNull();
    });

    it('words a field it has no label for by its own name', function () use ($shown) {
        $row = $shown('setting', 'updated', ['group' => 'general', 'changes' => [['field' => 'brand_new_field', 'from' => '', 'to' => 'x']]]);

        expect($row['changes'][0]['text'])->toBe('brand new field changed from none to x');
    });
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

/**
 * The whole first argument of every `->log(` call in app/, with its file.
 *
 * @return list<array{0: string, 1: string}>
 */
function activityLogCallArguments(): array
{
    $calls = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen(app_path()) + 1);
        $offset = 0;

        while (($at = strpos($source, '->log(', $offset)) !== false) {
            $offset = $at + 6;
            $depth = 0;
            $argument = '';

            // Up to the first comma or closing paren at this call's own depth.
            for ($i = $offset; $i < strlen($source); $i++) {
                $char = $source[$i];

                if ($char === '(' || $char === '[') {
                    $depth++;
                } elseif (($char === ')' || $char === ']') && $depth > 0) {
                    $depth--;
                } elseif (($char === ',' || $char === ')') && $depth === 0) {
                    break;
                }

                $argument .= $char;
            }

            // Only the activity logger's calls: a class's own `$this->log()`
            // helper writes to a log file, not the activity log.
            if (substr($source, max(0, $at - 5), 5) === '$this') {
                continue;
            }

            if (preg_match('/^\s*\$?[a-zA-Z_]/', $argument) === 1 || str_contains($argument, '.')) {
                $calls[] = [$relative, $argument];
            }
        }
    }

    return $calls;
}
