<?php

use App\Models\Application;
use App\Services\Server\Applications\ApplicationConfigMutator;
use Illuminate\Support\Facades\Process;

it('atomically changes one field without exposing the rest of the config in argv', function () {
    $application = new Application;
    $application->forceFill(['id' => 41]);
    $path = '/home/site/app/.env';
    $original = "DB_PASSWORD=do-not-log\nSITE_URL=http://site.test\n";
    $ran = collect();

    Process::fake(function ($process) use ($original, $ran) {
        $ran->push($process);
        $command = $process->command[0] === 'sudo'
            ? array_slice($process->command, 2)
            : $process->command;

        return ($command[0] ?? '') === 'cat'
            ? Process::result(output: $original)
            : Process::result(exitCode: 0);
    });

    $changed = app(ApplicationConfigMutator::class)->transform(
        $application,
        $path,
        fn (string $contents): string => str_replace(
            'SITE_URL=http://site.test',
            'SITE_URL=https://site.test',
            $contents,
        ),
    );

    expect($changed)->toBeTrue();

    $commands = $ran->map(fn ($process) => implode(' ', $process->command))->all();
    expect($commands)->toHaveCount(5)
        ->and(implode("\n", $commands))->not->toContain('do-not-log')
        ->and(implode("\n", $commands))->not->toContain('SITE_URL=https://site.test');

    $write = $ran->first(fn ($process) => in_array('tee', $process->command, true));
    expect((string) $write->input)->toContain('DB_PASSWORD=do-not-log')
        ->and((string) $write->input)->toContain('SITE_URL=https://site.test');

    $names = $ran->map(fn ($process) => $process->command[0] === 'sudo'
        ? ($process->command[2] ?? null)
        : ($process->command[0] ?? null));

    expect($names->all())->toBe(['cat', 'tee', 'chown', 'chmod', 'mv']);
});

it('does not rewrite an unchanged configuration', function () {
    $application = new Application;
    $application->forceFill(['id' => 42]);
    $ran = collect();

    Process::fake(function ($process) use ($ran) {
        $ran->push($process);

        return Process::result(output: "SITE_URL=http://site.test\n");
    });

    $changed = app(ApplicationConfigMutator::class)->transform(
        $application,
        '/home/site/app/.env',
        fn (string $contents): string => $contents,
    );

    expect($changed)->toBeFalse()
        ->and($ran)->toHaveCount(1)
        ->and($ran->first()->command)->toContain('cat');
});
