<?php

use App\Services\Server\Applications\EnvironmentInspector;
use App\Services\Server\Applications\FrameworkDetector;

/*
 * The inspector is what turns a text file into a screen, so these are the
 * tests that decide whether the UI can be written without knowing dotenv or
 * Laravel. Pure functions over a string — no server needed.
 */

function inspector(): EnvironmentInspector
{
    return app(EnvironmentInspector::class);
}

/** @param array<int, array<string, mixed>> $checks */
function codes(array $checks): array
{
    return array_map(fn (array $check): string => $check['code'], $checks);
}

describe('parsing', function () {
    it('reads pairs and ignores comments and blanks', function () {
        $raw = "# config\nAPP_ENV=production\n\nMAIL_FROM=hi@example.com\n";

        expect(inspector()->variables($raw))->toBe([
            ['key' => 'APP_ENV', 'value' => 'production', 'secret' => false],
            ['key' => 'MAIL_FROM', 'value' => 'hi@example.com', 'secret' => false],
        ]);
    });

    it('never returns a secret value', function () {
        $variables = inspector()->variables("DB_PASSWORD=hunter2\nAPI_TOKEN=abc\nAPP_KEY=base64:x\n");

        // Null, not a masked string: `••••` in a JSON response is still
        // something an attacker can read out of the response.
        foreach ($variables as $variable) {
            expect($variable['secret'])->toBeTrue()
                ->and($variable['value'])->toBeNull();
        }
    });

    it('strips quotes and trailing comments the way dotenv does', function () {
        $variables = inspector()->variables("A=\"hello world\"\nB=plain # trailing\nC='single'\n");

        expect(array_column($variables, 'value'))->toBe(['hello world', 'plain', 'single']);
    });
});

describe('syntax checks', function () {
    it('flags a line with no equals sign', function () {
        expect(codes(inspector()->checks("APP_ENV=production\nJUST_A_LINE\n", FrameworkDetector::UNKNOWN)))
            ->toContain('syntax_no_equals');
    });

    it('flags an unclosed quote, which swallows the lines below it', function () {
        expect(codes(inspector()->checks("A=\"open\nB=next\n", FrameworkDetector::UNKNOWN)))
            ->toContain('syntax_unbalanced_quote');
    });

    it('flags a duplicate key, because only the last one takes effect', function () {
        expect(codes(inspector()->checks("A=1\nA=2\n", FrameworkDetector::UNKNOWN)))
            ->toContain('duplicate_key');
    });

    it('flags `export` only where systemd reads the file', function () {
        $raw = "export A=1\n";

        // Node services are started by systemd, which rejects the keyword
        // outright — the unit simply will not start.
        expect(codes(inspector()->checks($raw, FrameworkDetector::NODE)))->toContain('syntax_export');

        // A PHP application reads the file itself, where `export` is fine.
        expect(codes(inspector()->checks($raw, FrameworkDetector::LARAVEL)))->not->toContain('syntax_export');
    });
});

describe('framework checks', function () {
    it('warns about debug mode and suggests the fix', function () {
        $checks = inspector()->checks("APP_DEBUG=true\nAPP_KEY=base64:x\n", FrameworkDetector::LARAVEL);
        $debug = collect($checks)->firstWhere('code', 'app_debug_on');

        // The frontend renders a one-click fix from these three fields without
        // knowing what Laravel is.
        expect($debug['key'])->toBe('APP_DEBUG')
            ->and($debug['value'])->toBe('true')
            ->and($debug['suggested'])->toBe('false')
            ->and($debug['severity'])->toBe('warning')
            ->and($debug['title'])->not->toBe('environment.checks.app_debug_on.title');
    });

    it('treats 1, on and yes as debug being on', function () {
        foreach (['1', 'on', 'yes', 'TRUE'] as $value) {
            // Not `toContain($needle, $message)` — the second argument there is
            // another value to look for, not a failure message, so the message
            // itself would silently become part of the assertion.
            $found = in_array('app_debug_on', codes(inspector()->checks(
                "APP_DEBUG={$value}\nAPP_KEY=x\n", FrameworkDetector::LARAVEL,
            )), true);

            expect($found)->toBeTrue("APP_DEBUG={$value} should count as on");
        }
    });

    it('reports a missing APP_KEY as an error, not a warning', function () {
        $checks = inspector()->checks("APP_ENV=production\n", FrameworkDetector::LARAVEL);

        expect(collect($checks)->firstWhere('code', 'app_key_missing')['severity'])->toBe('error');
    });

    it('reports an empty APP_KEY too', function () {
        expect(codes(inspector()->checks("APP_KEY=\n", FrameworkDetector::LARAVEL)))
            ->toContain('app_key_missing');
    });

    it('says nothing about a Laravel key on a Node application', function () {
        // Warnings invented for the wrong framework are how a checks list
        // becomes noise people learn to ignore.
        expect(codes(inspector()->checks("APP_DEBUG=true\n", FrameworkDetector::NODE)))
            ->not->toContain('app_debug_on');
    });

    it('flags a secret behind NEXT_PUBLIC_, which is already public', function () {
        $checks = inspector()->checks("NEXT_PUBLIC_API_TOKEN=abc\nNEXT_PUBLIC_URL=https://x\n", FrameworkDetector::NEXTJS);

        // Anything with that prefix is compiled into the browser bundle, so it
        // is not a risk of a leak — it is already served to every visitor.
        expect(codes($checks))->toContain('next_public_secret')
            ->and(collect($checks)->firstWhere('code', 'next_public_secret')['severity'])->toBe('error');

        // The non-secret one is fine and must not be flagged.
        expect(collect($checks)->where('code', 'next_public_secret'))->toHaveCount(1);
    });
});

it('produces a clean file with no checks at all', function () {
    $raw = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:abc\n# mail\nMAIL_FROM=hi@example.com\n";

    expect(inspector()->checks($raw, FrameworkDetector::LARAVEL))->toBe([]);
});
