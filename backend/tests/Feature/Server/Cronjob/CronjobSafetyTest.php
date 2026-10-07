<?php

use App\Models\Cronjob;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
| What cron itself will and will not read (frontend QA, 2026-09-28; measured on
| Ubuntu 26.04's cron 3.0pl1 on 2026-10-07).
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    Process::fake();
});

function postCron(array $overrides = [])
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->postJson('/api/cronjobs', array_merge([
        'name' => 'Job',
        'username' => 'www-data',
        'command' => 'true',
        'expression' => '0 0 * * *',
    ], $overrides));
}

it('keeps the cron.d file name short however long the job name is', function () {
    // A 251-character file name crashed cron (core dump): every job on the
    // server stopped, the panel's scheduler included.
    $name = str_repeat('q', 255);

    $first = postCron(['name' => $name])->assertCreated()->json('cronjob.slug');

    expect(strlen($first))->toBeLessThanOrEqual(Cronjob::MAX_SLUG_LENGTH)
        ->and(strlen($first))->toBeGreaterThan(40);

    Process::assertRan(fn ($p) => $p->command === ['tee', "/etc/cron.d/{$first}"]);
    Process::assertRan(fn ($p) => $p->command === ['touch', "/var/log/cronjobs/{$first}.log"]);
});

it('keeps a clashing long name within the limit, suffix included', function () {
    $name = str_repeat('a', 200);
    Cronjob::create(['name' => 'other', 'slug' => Cronjob::uniqueSlug($name), 'username' => 'root', 'command' => 'true', 'expression' => '* * * * *']);

    $slug = Cronjob::uniqueSlug($name);

    expect($slug)->toEndWith('-2')
        ->and(strlen($slug))->toBeLessThanOrEqual(Cronjob::MAX_SLUG_LENGTH);
});

it('does not leave a hyphen where the name was cut', function () {
    // "a-a-a-…" cut at 64 can end on the separator.
    $slug = Cronjob::uniqueSlug(str_repeat('a ', 100));

    expect($slug)->not->toEndWith('-')
        ->and(strlen($slug))->toBeLessThanOrEqual(Cronjob::MAX_SLUG_LENGTH);
});

it('refuses a schedule cron itself cannot read', function (string $expression) {
    // `0 0 L * *` was accepted, and cron logged "this crontab file will be
    // ignored" — the job never ran while the list showed its next run.
    postCron(['expression' => $expression])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expression']);
})->with([
    'last day' => '0 0 L * *',
    'nearest weekday' => '0 0 15W * *',
    'question mark' => '0 0 ? * *',
    'nth weekday' => '0 0 * * 1#2',
    'six fields' => '0 0 0 * * *',
    'reboot (no next run)' => '@reboot',
]);

it('accepts every schedule form cron reads', function (string $expression) {
    postCron(['name' => 'job '.md5($expression), 'expression' => $expression])->assertCreated();
})->with([
    'plain' => '0 0 * * *',
    'steps' => '*/5 1-3/2 * * *',
    'lists' => '0 0,12 1,15 * *',
    'day names range' => '0 9 * * mon-fri',
    'day names list' => '0 9 * * mon,wed',
    'month names' => '0 0 1 jan-mar *',
    'sunday as 7' => '0 0 * * 7',
    'upper case names' => '0 9 * * MON-FRI',
    'shortcut' => '@daily',
]);

it('refuses a command whose shell comment would swallow the rest of the cron line', function (string $command) {
    // Written as `( cmd # note ) >> log …` — the comment eats the `)` and the
    // redirect: nothing is logged and the output goes nowhere.
    postCron(['command' => $command])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['command' => __('errors/cronjob.shell_comment')]);
})->with([
    'trailing note' => 'echo hi # note',
    'whole line' => '# disabled for now',
    'after a semicolon' => 'cd /tmp;#note',
    'after a quoted word' => 'echo "x" #note',
    'after an escaped quote' => 'echo \" # note',
]);

it('leaves a # alone where the shell does not read it as a comment', function (string $command) {
    postCron(['name' => 'job '.md5($command), 'command' => $command])->assertCreated();
})->with([
    'double quotes' => 'echo "#tag"',
    'single quotes' => "echo '# not a comment'",
    'inside a word' => 'echo a#b',
    'argument count' => 'echo $#',
    'url fragment' => 'curl -s https://example.com/page#section',
    'escaped' => 'echo \# literal',
    'after an escaped space' => 'echo a\ #b',
    'word that starts with an escaped space' => 'echo \ #b',
    'escaped quote inside quotes' => 'echo "a\" #b"',
    'word right after a quote' => 'echo "x"#y',
]);

it('refuses a shell comment on edit too', function () {
    $id = postCron()->assertCreated()->json('cronjob.id');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/cronjobs/{$id}", ['command' => 'echo hi # note'])
        ->assertJsonValidationErrors(['command']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/cronjobs/{$id}", ['expression' => '0 0 L * *'])
        ->assertJsonValidationErrors(['expression']);
});

it('lists jobs created in the same second newest first, by id', function () {
    $this->travelTo(now()->startOfSecond());

    $ids = collect(range(1, 5))->map(fn ($i) => postCron(['name' => "same second {$i}"])->json('cronjob.id'));

    $listed = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs?per_page=10')
        ->json('cronjobs.*.id');

    expect($listed)->toBe($ids->reverse()->values()->all());
});

it('lists every account a job runs as, across all pages', function () {
    foreach (['root', 'www-data', 'nobody'] as $i => $user) {
        Cronjob::create(['name' => "j{$i}", 'slug' => "j{$i}", 'username' => $user, 'command' => 'true', 'expression' => '* * * * *']);
    }

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs?per_page=10&page=2')
        ->assertOk()
        ->assertJsonPath('cronjobs', [])
        ->assertJsonPath('meta.usernames', ['nobody', 'root', 'www-data']);
});

it('says when the cron daemon is not running', function (string $state, int $exit, ?bool $expected) {
    // A closure replaces beforeEach's catch-all; an array would be merged
    // behind it and never reached.
    Process::fake(fn ($process) => in_array('is-active', (array) $process->command, true)
        ? Process::result(output: "{$state}\n", exitCode: $exit)
        : Process::result());

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs')
        ->assertOk()
        ->assertJsonPath('meta.cron_running', $expected);

    Process::assertRan(fn ($p) => in_array('is-active', $p->command, true) && in_array('cron', $p->command, true));
})->with([
    'running' => ['active', 0, true],
    'stopped' => ['inactive', 3, false],
    'crashed' => ['failed', 3, false],
    'cannot tell' => ['', 1, null],
]);
