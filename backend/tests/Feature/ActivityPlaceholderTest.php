<?php

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
| Every activity sentence, rendered with the properties its call site really
| logs, must come out with no `:placeholder` left in it (FS-C22 #21).
|
| Laravel leaves a placeholder it has no value for exactly as written, so a
| sentence asking for `:name` on an event that logs `application` shows
| "Deleted :name" to every viewer, and nothing fails anywhere.
| ActivityTranslationTest proves each event HAS a sentence; this proves the
| sentence and the event agree.
|
| The properties are read from the source, the way ActivityTranslationTest
| reads the keys: a maintained list is the thing that drifts.
*/

/**
 * Properties of the few call sites the source cannot answer for, keyed by
 * `file:key`. Each says where its shape comes from.
 *
 * @return array<string, list<string>>
 */
function activityPropertiesByHand(): array
{
    return [
        // `$killed` is ProcessKiller::kill()'s return value; its declared
        // shape is `array{pid, command, user, signal}`.
        'app/Http/Controllers/API/Server/DashboardController.php:server.process_killed' => ['pid', 'command', 'user', 'signal'],
    ];
}

/**
 * Every `->log(<key>, <subject>, <properties>)` call in app/ that names an
 * activity event, with the property names it passes.
 *
 * A key may be built (`'backup.'.$verb`, a ternary, a match): each literal in
 * it that is a whole event key counts, and a literal prefix stands for every
 * event under it that is not logged literally anywhere else. Properties may be
 * a literal array, or a variable assigned one in the same function — spreads
 * of either included. `props` is null when neither can be read.
 *
 * @return list<array{key: string, props: list<string>|null, at: string}>
 */
function activityCallSites(): array
{
    $events = require lang_path('en/activity.php');
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/'.ltrim(str_replace(app_path(), '', $file->getPathname()), '/');
        $ast = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
        $functions = $finder->find($ast, fn (Node $n): bool => $n instanceof Node\FunctionLike);

        foreach ($finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'log' || ! isset($call->args[0])) {
                continue;
            }

            $literals = array_map(
                fn (Node\Scalar\String_ $s): string => $s->value,
                $finder->findInstanceOf($call->args[0]->value, Node\Scalar\String_::class),
            );

            $keys = [];
            $prefixes = [];

            foreach ($literals as $literal) {
                if (array_key_exists($literal, $events)) {
                    $keys[] = $literal;
                } elseif (preg_match('/^[a-z0-9_]+[._]$|^[a-z0-9_]+\.[a-z0-9_]+_$/', $literal) === 1) {
                    $prefixes[] = $literal;
                }
            }

            if ($keys === [] && $prefixes === []) {
                continue;
            }

            $properties = null;

            foreach ($call->args as $i => $arg) {
                if (($arg->name?->toString() ?? ($i === 2 ? 'properties' : null)) === 'properties') {
                    $properties = $arg->value;
                }
            }

            $enclosing = null;

            foreach ($functions as $function) {
                if ($function->getStartLine() <= $call->getStartLine() && $function->getEndLine() >= $call->getEndLine()
                    && ($enclosing === null || $function->getStartLine() >= $enclosing->getStartLine())) {
                    $enclosing = $function;
                }
            }

            $found[] = [
                'keys' => $keys,
                'prefixes' => $prefixes,
                'props' => $properties === null ? [] : activityArrayKeys($properties, $enclosing, $finder),
                'file' => $relative,
                'at' => $relative.':'.$call->getStartLine(),
            ];
        }
    }

    $literal = array_merge(...array_map(fn (array $site): array => $site['keys'], $found));
    $sites = [];

    foreach ($found as $site) {
        $keys = $site['keys'];

        foreach ($site['prefixes'] as $prefix) {
            foreach (array_keys($events) as $event) {
                if (str_starts_with($event, $prefix) && ! in_array($event, $literal, true)) {
                    $keys[] = $event;
                }
            }
        }

        foreach (array_unique($keys) as $key) {
            $sites[] = [
                'key' => $key,
                'props' => $site['props'] ?? activityPropertiesByHand()["{$site['file']}:{$key}"] ?? null,
                'at' => $site['at'],
            ];
        }
    }

    return $sites;
}

/**
 * The string keys of an array expression, or null when they cannot be known.
 *
 * @return list<string>|null
 */
function activityArrayKeys(Node\Expr $expr, ?Node\FunctionLike $scope, NodeFinder $finder, int $depth = 0): ?array
{
    if ($depth > 3) {
        return null;
    }

    if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && $scope !== null) {
        $keys = null;

        foreach ($finder->findInstanceOf((array) $scope->getStmts(), Node\Expr\Assign::class) as $assign) {
            // `$properties = [...]`
            if ($assign->var instanceof Node\Expr\Variable && $assign->var->name === $expr->name) {
                $assigned = activityArrayKeys($assign->expr, $scope, $finder, $depth + 1);

                if ($assigned === null) {
                    return null;
                }

                $keys = array_merge($keys ?? [], $assigned);
            }

            // `$properties['reason'] = ...`
            if ($assign->var instanceof Node\Expr\ArrayDimFetch && $assign->var->var instanceof Node\Expr\Variable
                && $assign->var->var->name === $expr->name && $assign->var->dim instanceof Node\Scalar\String_) {
                $keys = array_merge($keys ?? [], [$assign->var->dim->value]);
            }
        }

        return $keys === null ? null : array_values(array_unique($keys));
    }

    if (! $expr instanceof Node\Expr\Array_) {
        return null;
    }

    $keys = [];

    foreach ($expr->items as $item) {
        if ($item === null) {
            continue;
        }

        if ($item->unpack) {
            $spread = activityArrayKeys($item->value, $scope, $finder, $depth + 1);

            if ($spread === null) {
                return null;
            }

            $keys = array_merge($keys, $spread);

            continue;
        }

        if (! $item->key instanceof Node\Scalar\String_) {
            return null;
        }

        $keys[] = $item->key->value;
    }

    return array_values(array_unique($keys));
}

it('finds the activity calls it is meant to check', function () {
    $sites = activityCallSites();

    // Several hundred today. A handful would mean the scan broke, not that
    // the code stopped logging.
    expect(count($sites))->toBeGreaterThan(200);

    $unread = array_values(array_map(
        fn (array $site): string => "{$site['key']} at {$site['at']}",
        array_filter($sites, fn (array $site): bool => $site['props'] === null),
    ));

    // A call whose properties cannot be read is a call this test cannot
    // check. Give it a literal array, or say its shape in
    // activityPropertiesByHand().
    expect($unread)->toBe([], 'activity calls with unreadable properties: '.implode('; ', $unread));
});

it('fills every placeholder of every activity sentence, in every locale', function () {
    $leftovers = [];

    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        app()->setLocale($locale);

        foreach (activityCallSites() as $site) {
            [$type, $action] = explode('.', $site['key'], 2);

            $row = (new ActivityLog)->forceFill([
                'type' => $type,
                'action' => $action,
                'properties' => array_fill_keys((array) $site['props'], 'VALUE'),
            ]);

            $description = (string) (new ActivityLogResource($row))->resolve()['description'];

            if (preg_match_all('/:[a-z_]+/i', $description, $m) > 0) {
                $leftovers[] = "[{$locale}] {$site['key']} at {$site['at']} logs [".implode(', ', (array) $site['props']).'] and leaves '.implode(' ', array_unique($m[0])).": {$description}";
            }
        }
    }

    expect($leftovers)->toBe([], "activity sentences with a placeholder nobody fills:\n".implode("\n", $leftovers));
});

it('declares each activity sentence once per locale', function () {
    // A PHP array literal keeps the LAST of two equal keys, silently.
    // `application.updated` was written twice — the settings save and the
    // version update — and the version update's ":name from :from to :to" was
    // what every settings save showed.
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        preg_match_all("/^\s*'([a-z0-9_]+\.[a-z0-9_]+)'\s*=>/m", (string) file_get_contents(lang_path("{$locale}/activity.php")), $m);

        $twice = array_keys(array_filter(array_count_values($m[1]), fn (int $n): bool => $n > 1));

        expect($twice)->toBe([], "{$locale}/activity.php declares these more than once: ".implode(', ', $twice));
    }
});
