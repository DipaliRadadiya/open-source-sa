<?php

use Illuminate\Support\Facades\File;

/**
 * Lang files are only parsed when a key from them is first requested, in that
 * locale. So a syntax error in one sits invisible through a whole green suite
 * and surfaces as a 500 for the first user who sets that language — which is
 * exactly what happened to the French files: an unescaped apostrophe in
 * `n'est pas installé` shipped in three separate commits.
 */
function langFiles(): array
{
    return collect(File::allFiles(lang_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->map(fn ($file) => $file->getPathname())
        ->values()
        ->all();
}

it('has lang files that are all valid PHP', function () {
    $broken = [];

    foreach (langFiles() as $path) {
        // Not `require` — a parse error there is fatal and takes the whole
        // suite down instead of reporting which file is at fault.
        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);

        if ($status !== 0) {
            $broken[] = str_replace(lang_path().'/', '', $path).': '.implode(' ', $output);
        }

        $output = [];
    }

    expect($broken)->toBe([]);
});

it('has lang files that return a flat array of strings', function () {
    foreach (langFiles() as $path) {
        $name = str_replace(lang_path().'/', '', $path);

        $value = require $path;

        expect($value)->toBeArray("{$name} does not return an array");

        array_walk_recursive($value, function ($line, $key) use ($name) {
            expect($line)->toBeString("{$name}.{$key} is not a string");
        });
    }
});

it('keeps every locale key-complete against English', function () {
    $english = collect(langFiles())
        ->filter(fn (string $path) => str_starts_with(str_replace(lang_path().'/', '', $path), 'en/'));

    $locales = collect(config('app.available_locales', ['en']))->reject(fn ($l) => $l === 'en');

    $missing = [];

    foreach ($english as $path) {
        $relative = str_replace(lang_path().'/en/', '', $path);
        $keys = array_keys(require $path);

        foreach ($locales as $locale) {
            $translated = lang_path()."/{$locale}/{$relative}";

            if (! file_exists($translated)) {
                $missing[] = "{$locale}/{$relative} (whole file)";

                continue;
            }

            foreach (array_diff($keys, array_keys(require $translated)) as $key) {
                $missing[] = "{$locale}/{$relative}: {$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});
