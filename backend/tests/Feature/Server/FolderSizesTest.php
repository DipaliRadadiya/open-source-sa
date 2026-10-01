<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;

/*
 * Every folder's size in one `du`, for the file manager's size column
 * (operator, 2026-10-01). Kept five minutes; anything the panel changes
 * forgets it.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->application = Application::factory()->create([
        'system_user_id' => SystemUser::factory()->create()->id,
    ]);
    $this->duRuns = new ArrayObject;
});

/**
 * `du` answers for whatever directory it was asked about; the probes that run
 * first say "a directory".
 *
 * @param  array<string, int>  $folders
 */
function fakeFolderSizes(array $folders, int $exitCode = 0, bool $timedOut = false): void
{
    $runs = test()->duRuns;

    Process::fake(function ($process) use ($folders, $exitCode, $runs) {
        $command = $process->command;

        if (in_array('du', $command, true)) {
            $runs[] = $command;
            $target = end($command);
            $lines = [];

            foreach ($folders as $name => $bytes) {
                $lines[] = $bytes."\t{$target}/{$name}";
            }
            $lines[] = array_sum($folders) + 4096 ."\t{$target}";

            return Process::result(output: implode("\0", $lines)."\0", exitCode: $exitCode);
        }

        if (in_array('-printf', $command, true)) {
            // The targets the change tests create do not exist yet.
            foreach ($command as $arg) {
                if (str_ends_with((string) $arg, '/wp-content/new') || str_ends_with((string) $arg, '/wp-content/b')) {
                    return Process::result(exitCode: 1);
                }
            }

            return Process::result(output: "d\t4096");
        }

        return Process::result(exitCode: 0);
    });
}

function sizesUrl(string $query = 'path=wp-content'): string
{
    return '/api/applications/'.test()->application->id.'/files/sizes?'.$query;
}

it('returns every folder in the directory from one du', function () {
    fakeFolderSizes(['plugins' => 9_579_803, 'uploads' => 52_428_800, ".hidden\nname" => 10]);

    $response = $this->getJson(sizesUrl())->assertOk();

    expect($response->json('path'))->toBe('wp-content')
        ->and($response->json('sizes.plugins'))->toBe(['size' => 9_579_803, 'size_human' => '9.1 MB'])
        ->and($response->json('sizes.uploads.size'))->toBe(52_428_800)
        // NUL-separated, so a name with a newline is still one folder.
        ->and($response->json('sizes')[".hidden\nname"]['size'])->toBe(10)
        ->and($response->json('total.size'))->toBe(9_579_803 + 52_428_800 + 10 + 4096)
        ->and($response->json('complete'))->toBeTrue()
        ->and($response->json('measured_at'))->not->toBeNull()
        ->and($this->duRuns)->toHaveCount(1);
});

it('runs as the site user, at the lowest priority, on one filesystem, one level deep', function () {
    fakeFolderSizes(['plugins' => 1]);

    $this->getJson(sizesUrl())->assertOk();

    $command = $this->duRuns[0];

    expect($command[0])->toBe('runuser')
        ->and(array_slice($command, 4, 6))->toBe(['nice', '-n', '19', 'ionice', '-c', '3'])
        ->and($command)->toContain('-bx0')
        ->and($command)->toContain('--max-depth=1');
});

it('answers from memory for five minutes, and measures again on refresh', function () {
    fakeFolderSizes(['plugins' => 1]);

    $this->getJson(sizesUrl())->assertOk();
    $this->getJson(sizesUrl())->assertOk();
    expect($this->duRuns)->toHaveCount(1);

    $this->getJson(sizesUrl('path=wp-content&refresh=1'))->assertOk();
    expect($this->duRuns)->toHaveCount(2);

    $this->travel(301)->seconds();
    $this->getJson(sizesUrl())->assertOk();
    expect($this->duRuns)->toHaveCount(3);
});

it('forgets the sizes when the panel changes the site', function (callable $change) {
    fakeFolderSizes(['plugins' => 1]);
    $this->getJson(sizesUrl())->assertOk();

    $change($this->application->id)->assertSuccessful();

    $this->getJson(sizesUrl())->assertOk();
    expect($this->duRuns)->toHaveCount(2);
})->with([
    'new folder' => [fn (int $id) => test()->postJson("/api/applications/{$id}/files/directories", ['path' => 'wp-content/new'])],
    'rename' => [fn (int $id) => test()->putJson("/api/applications/{$id}/files/rename", ['path' => 'wp-content/a', 'target' => 'wp-content/b'])],
]);

it('keeps what it measured when du could not read everything', function () {
    // du exits 1 over a few unreadable files and still prints the rest.
    fakeFolderSizes(['plugins' => 7], exitCode: 1);

    $this->getJson(sizesUrl())->assertOk()->assertJsonPath('sizes.plugins.size', 7);
});

it('refuses a path outside the site', function () {
    fakeFolderSizes([]);

    $this->getJson(sizesUrl('path=../../etc'))->assertStatus(422);
    expect($this->duRuns)->toHaveCount(0);
});

it('needs file access', function () {
    fakeFolderSizes(['plugins' => 1]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(sizesUrl())->assertForbidden();
    expect($this->duRuns)->toHaveCount(0);
});
