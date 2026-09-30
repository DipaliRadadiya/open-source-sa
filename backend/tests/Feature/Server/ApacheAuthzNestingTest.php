<?php

/**
 * Apache refuses a negation anywhere but inside a `<RequireAll>`.
 *
 * A negation (`<RequireNone>`, `Require not …`) can only ever deny, so in a
 * `<RequireAny>` it "has no effect" — and Apache does not ignore it, it fails
 * the whole config test. The WAF's enforce block nested a `<RequireNone>`
 * straight in a `<RequireAny>`, so turning the WAF on for any Apache site
 * answered 500 and changed nothing (found on the Apache test box).
 *
 * Read from the template source, because the nesting is fixed text: the
 * Blade conditionals only add or drop leaf `Require` lines inside it.
 */
function apacheTemplateSources(): array
{
    $sources = [];

    foreach (glob(base_path('resources/views/server/vhosts/apache/*.blade.php')) ?: [] as $path) {
        $sources[basename($path)] = (string) file_get_contents($path);
    }

    return $sources;
}

it('finds the Apache templates that carry authorization blocks', function () {
    $withAuthz = array_filter(apacheTemplateSources(), fn (string $source) => str_contains($source, '<RequireNone>'));

    // Without this, an empty glob makes the check below vacuous.
    expect(array_keys($withAuthz))->toContain('_php-body.blade.php', '_static-body.blade.php', '_node-body.blade.php');
});

it('never puts a negation directly inside a RequireAny', function () {
    $misplaced = [];

    foreach (apacheTemplateSources() as $file => $source) {
        $stack = [];

        foreach (explode("\n", $source) as $number => $line) {
            $line = trim($line);

            if (preg_match('/^<(RequireAll|RequireAny|RequireNone)>$/', $line, $open)) {
                if ($open[1] === 'RequireNone' && end($stack) === 'RequireAny') {
                    $misplaced[] = "{$file}:".($number + 1).' <RequireNone>';
                }

                $stack[] = $open[1];
            } elseif (preg_match('/^<\/(RequireAll|RequireAny|RequireNone)>$/', $line)) {
                array_pop($stack);
            } elseif (str_starts_with($line, 'Require not ') && end($stack) === 'RequireAny') {
                $misplaced[] = "{$file}:".($number + 1).' Require not';
            }
        }

        expect($stack)->toBe([], "{$file} leaves a Require block unclosed");
    }

    expect($misplaced)->toBe([]);
});
