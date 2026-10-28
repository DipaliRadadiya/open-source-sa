<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Cron\CronPhpBinary;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * #5 (support list, 2026-10-27): a site's cron jobs and its PHP version. The
 * panel shows which PHP each job runs and offers to switch the ones the user
 * ticks; it never switches one on its own.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    // PHP 8.3 and 8.4 installed, 8.2 not; the server default is 8.4 unless a
    // test says otherwise. The real runtime reads both, so nothing here
    // depends on the machine running the tests.
    $this->base = sys_get_temp_dir().'/sv-oss-cronphp-'.getmypid();
    File::deleteDirectory($this->base);
    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->base}/php/{$version}/fpm", 0755, true);
    }
    config([
        'server.php_dir' => "{$this->base}/php",
        'server.php_binary_pattern' => '/usr/bin/php{version}',
        'server.php_path_link' => "{$this->base}/no-link",
        'server.cron_d' => "{$this->base}/cron.d",
        'server.cronjob_log_dir' => "{$this->base}/logs",
        'server.cronjob_logrotate_file' => "{$this->base}/logrotate",
    ]);
    $this->runs = cpxFake('8.4');

    $owner = SystemUser::create(['username' => 'shopper', 'home_path' => '/home/shopper']);
    $this->site = Application::forceCreate([
        'system_user_id' => $owner->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        'php_version' => '8.3',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * Every command answered OK, update-alternatives naming `$default`. Returns
 * what ran, and what was piped in, so a test can see what was written.
 */
function cpxFake(string $default): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $default) {
        $command = (array) $process->command;
        $runs[] = ['command' => $command, 'input' => (string) $process->input];

        if (($command[0] ?? '') === 'update-alternatives' && in_array('--query', $command, true)) {
            return Process::result(output: "Name: php\nLink: /usr/bin/php\nStatus: auto\nValue: /usr/bin/php{$default}\n");
        }

        return Process::result();
    });

    return $runs;
}

function cpxJob(string $name, string $command, ?Application $site = null, bool $owned = false): Cronjob
{
    return Cronjob::create([
        'name' => $name, 'slug' => Cronjob::uniqueSlug($name), 'username' => 'shopper',
        'application_id' => $site?->id, 'application_owned' => $owned,
        'command' => $command, 'expression' => '*/5 * * * *', 'active' => true,
    ]);
}

/** What was written to a job's cron.d file, or null if nothing was. */
function cpxWritten(ArrayObject $runs, Cronjob $job): ?string
{
    $write = collect($runs)->last(fn ($run) => $run['command'][0] === 'tee' && str_ends_with($run['command'][1], "cron.d/{$job->slug}"));

    return $write['input'] ?? null;
}

describe('which PHP a command runs', function () {

    it('reads the version, the server default, or nothing', function (string $command, ?string $version) {
        expect(app(CronPhpBinary::class)->version($command))->toBe($version);
    })->with([
        'installer, FPM' => ['/usr/bin/php8.2 -f /home/a/public_html/wp-cron.php', '8.2'],
        'bare versioned' => ['php8.3 /home/a/script.php', '8.3'],
        'local bin versioned' => ['/usr/local/bin/php8.1 x.php', '8.1'],
        'OpenLiteSpeed tree' => ['/usr/local/lsws/lsphp82/bin/php -f /home/a/cron.php', '8.2'],
        'OpenLiteSpeed lsphp' => ['/usr/local/lsws/lsphp84/bin/lsphp x.php', '8.4'],
        'lsphp with a dot' => ['/usr/bin/lsphp8.1 x.php', '8.1'],
        'plain php' => ['php /home/a/artisan schedule:run', 'default'],
        'after cd &&' => ['cd /home/a && php artisan schedule:run', 'default'],
        'php link' => ['/usr/bin/php -f x.php', 'default'],
        'local php link' => ['/usr/local/bin/php -f x.php', 'default'],
        'same version twice' => ['/usr/bin/php8.2 a.php; /usr/bin/php8.2 b.php', '8.2'],
        'two versions' => ['/usr/bin/php8.2 a.php && /usr/bin/php8.3 b.php', null],
        'version and plain' => ['php8.2 a.php && php b.php', null],
        'php only as a word' => ['echo php >> /tmp/x', null],
        'php in a file name' => ['/home/a/backup-php8.2.sh', null],
        'php at the end of a script name' => ['/home/a/run-php8.2 --now', null],
        'someone else\'s php' => ['/opt/php8.2/bin/php x.php', null],
        'no php' => ['wget -q -O- https://shop.test/cron', null],
    ]);

    it('changes only the PHP word, every one of them', function () {
        $binary = app(CronPhpBinary::class);

        expect($binary->switchTo('/usr/bin/php8.2 -f /home/a/cron.php >/dev/null', '8.4'))
            ->toBe('/usr/bin/php8.4 -f /home/a/cron.php >/dev/null')
            ->and($binary->switchTo('cd /home/a && php artisan schedule:run', '8.3'))
            ->toBe('cd /home/a && /usr/bin/php8.3 artisan schedule:run')
            ->and($binary->switchTo('php8.2 a.php; php8.2 "b php8.2.php"', '8.3'))
            ->toBe('/usr/bin/php8.3 a.php; /usr/bin/php8.3 "b php8.2.php"')
            ->and($binary->switchTo('wget https://x', '8.3'))->toBeNull()
            ->and($binary->switchTo('/usr/bin/php8.2 a && /usr/bin/php8.3 b', '8.4'))->toBeNull();
    });
});

describe('Part A: each job says which PHP it runs', function () {

    it('marks a site job that is not on the site\'s PHP', function () {
        $old = cpxJob('Old cron', '/usr/bin/php8.2 -f /home/shopper/cron.php', $this->site);
        $same = cpxJob('Same cron', '/usr/bin/php8.3 -f /home/shopper/cron.php', $this->site);
        $plain = cpxJob('Plain cron', 'php /home/shopper/artisan schedule:run', $this->site);
        $server = cpxJob('Server cron', '/usr/bin/php8.2 /root/x.php');
        $unknown = cpxJob('Curl cron', 'curl -s https://shop.test/cron', $this->site);

        $rows = collect($this->getJson('/api/cronjobs?per_page=100')->assertOk()->json('cronjobs'))->keyBy('id');

        expect($rows[$old->id])->toMatchArray(['php_version' => '8.2', 'matches_site_php' => false])
            ->and($rows[$same->id])->toMatchArray(['php_version' => '8.3', 'matches_site_php' => true])
            // Plain php runs the default, 8.4 — not the site's 8.3.
            ->and($rows[$plain->id])->toMatchArray(['php_version' => 'default', 'matches_site_php' => false])
            ->and($rows[$server->id])->toMatchArray(['php_version' => '8.2', 'matches_site_php' => null])
            ->and($rows[$unknown->id])->toMatchArray(['php_version' => null, 'matches_site_php' => null]);
    });

    it('asks the server for its default once per list, not once per job', function () {
        foreach (range(1, 5) as $i) {
            cpxJob("Plain {$i}", 'php /home/shopper/x.php', $this->site);
        }

        $this->getJson('/api/cronjobs?per_page=100')->assertOk();

        expect(collect($this->runs)->filter(fn ($run) => $run['command'][0] === 'update-alternatives')->count())->toBe(1);
    });
});

describe('Part B: after a site\'s PHP changes, the jobs are offered, not changed', function () {

    it('lists the site\'s jobs on another PHP, ticks the pinned ones, and writes nothing', function () {
        $this->runs = cpxFake('8.3');
        $pinned = cpxJob('WP cron', '/usr/bin/php8.3 -f /home/shopper/wp-cron.php', $this->site, owned: true);
        $plain = cpxJob('Artisan', 'cd /home/shopper && php artisan schedule:run', $this->site);
        cpxJob('Already 8.4', '/usr/bin/php8.4 x.php', $this->site);
        cpxJob('Other site', '/usr/bin/php8.3 y.php');

        $review = collect($this->putJson("/api/applications/{$this->site->id}/php", ['php_version' => '8.4'])
            ->assertOk()
            ->json('cronjobs_to_review'))->keyBy('id');

        expect($review->keys()->sort()->values()->all())->toBe(collect([$pinned->id, $plain->id])->sort()->values()->all())
            ->and($review[$pinned->id])->toMatchArray([
                'php_version' => '8.3', 'suggested' => true,
                'new_command' => '/usr/bin/php8.4 -f /home/shopper/wp-cron.php',
            ])
            // Plain php may follow the default on purpose: offered, unticked.
            ->and($review[$plain->id])->toMatchArray([
                'php_version' => 'default', 'suggested' => false,
                'new_command' => 'cd /home/shopper && /usr/bin/php8.4 artisan schedule:run',
            ])
            // Nothing changed: not the rows, not a single cron.d file.
            ->and($pinned->fresh()->command)->toBe('/usr/bin/php8.3 -f /home/shopper/wp-cron.php')
            ->and(collect($this->runs)->filter(fn ($run) => str_contains(implode(' ', $run['command']), 'cron.d'))->all())->toBe([]);
    });
});

describe('Part B: switching the jobs the user ticked', function () {

    it('switches only the ticked jobs, only their PHP, and keeps them the site\'s', function () {
        $ticked = cpxJob('WP cron', '/usr/bin/php8.3 -f /home/shopper/wp-cron.php >/dev/null 2>&1', $this->site, owned: true);
        $left = cpxJob('Left alone', '/usr/bin/php8.3 -f /home/shopper/other.php', $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$ticked->id]])
            ->assertOk()
            ->assertJsonPath('switched.0.id', $ticked->id)
            ->assertJsonPath('switched.0.php_version', '8.4');

        $ticked->refresh();

        expect($ticked->command)->toBe('/usr/bin/php8.4 -f /home/shopper/wp-cron.php >/dev/null 2>&1')
            // Removed with the site still: swapping its PHP is not taking it over.
            ->and($ticked->application_owned)->toBeTrue()
            ->and(cpxWritten($this->runs, $ticked))->toContain('( /usr/bin/php8.4 -f /home/shopper/wp-cron.php >/dev/null 2>&1 )')
            ->and($left->fresh()->command)->toBe('/usr/bin/php8.3 -f /home/shopper/other.php')
            ->and(cpxWritten($this->runs, $left))->toBeNull()
            ->and(ActivityLog::where('type', 'cronjob')->where('action', 'php_switched')->value('properties'))
            ->toMatchArray(['name' => 'WP cron', 'from' => '8.3', 'to' => '8.4']);
    });

    it('pins a plain php job when the user ticks it, and words "default" in the activity', function () {
        $plain = cpxJob('Artisan', 'cd /home/shopper && php artisan schedule:run', $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.3', 'cronjob_ids' => [$plain->id]])->assertOk();

        expect($plain->fresh()->command)->toBe('cd /home/shopper && /usr/bin/php8.3 artisan schedule:run');

        $line = collect($this->getJson('/api/activity-log?filter[type]=cronjob')->json('activity_log'))->firstWhere('action', 'php_switched');
        expect($line['description'])->toBe('Switched cron job Artisan from PHP default to 8.3');

        // Worded in the viewer's language, not stored as the logger's: in
        // English the code and the word happen to be the same.
        $line = collect($this->withHeader('Accept-Language', 'de')->getJson('/api/activity-log?filter[type]=cronjob')->json('activity_log'))->firstWhere('action', 'php_switched');
        expect($line['description'])->toBe('Cronjob Artisan von PHP Standard auf 8.3 umgestellt');
    });

    it('leaves a job already on that version alone', function () {
        $same = cpxJob('Same', '/usr/bin/php8.4 x.php', $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$same->id]])
            ->assertOk()
            ->assertJsonPath('switched', [])
            ->assertJsonPath('unchanged', [$same->id]);

        expect(cpxWritten($this->runs, $same))->toBeNull();
    });

    it('refuses a version that is not installed, and writes nothing', function () {
        $job = cpxJob('WP cron', '/usr/bin/php8.3 x.php', $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.2', 'cronjob_ids' => [$job->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');

        expect($job->fresh()->command)->toBe('/usr/bin/php8.3 x.php');
    });

    it('refuses another site\'s job and a server-level one', function () {
        $owner = SystemUser::create(['username' => 'other', 'home_path' => '/home/other']);
        $other = Application::forceCreate([
            'system_user_id' => $owner->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
            'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.3',
        ]);
        $theirs = cpxJob('Their cron', '/usr/bin/php8.3 x.php', $other);
        $server = cpxJob('Server cron', '/usr/bin/php8.3 y.php');

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$theirs->id, $server->id, 999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cronjob_ids.0', 'cronjob_ids.1', 'cronjob_ids.2']);

        expect($theirs->fresh()->command)->toBe('/usr/bin/php8.3 x.php')
            ->and($server->fresh()->command)->toBe('/usr/bin/php8.3 y.php');
    });

    it('refuses a job whose PHP it cannot find, and switches none of the batch', function () {
        $fine = cpxJob('Fine', '/usr/bin/php8.3 x.php', $this->site);
        $curl = cpxJob('Curl', 'curl -s https://shop.test/cron', $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$fine->id, $curl->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cronjob_ids.1');

        expect($fine->fresh()->command)->toBe('/usr/bin/php8.3 x.php');
    });

    it('refuses a switch that would make the command longer than an edit allows', function () {
        // `php` → `/usr/bin/php8.4` adds 12 characters; 995 + 12 is past 1000.
        $job = cpxJob('Long', 'php '.str_repeat('a', 991), $this->site);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$job->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cronjob_ids.0');
    });

    it('refuses a user who can only view cron jobs', function () {
        $job = cpxJob('WP cron', '/usr/bin/php8.3 x.php', $this->site);
        $viewer = User::factory()->create();
        grantPermission($viewer, 'cronjob');
        grantPermission($viewer, 'app_php', manage: true);
        $this->actingAs($viewer);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$job->id]])
            ->assertForbidden();

        expect($job->fresh()->command)->toBe('/usr/bin/php8.3 x.php');
    });

    it('lets a user who manages cron jobs switch them', function () {
        $job = cpxJob('WP cron', '/usr/bin/php8.3 x.php', $this->site);
        $manager = User::factory()->create();
        grantPermission($manager, 'cronjob', manage: true);
        grantPermission($manager, 'app_php');
        $this->actingAs($manager);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$job->id]])
            ->assertOk();
    });
});

describe('Part C: changing the server default warns, changes nothing', function () {

    it('lists the plain php jobs that now run on the new default', function () {
        $plain = cpxJob('Artisan', 'php /home/shopper/artisan schedule:run', $this->site);
        $serverPlain = cpxJob('Server plain', '/usr/bin/php /root/x.php');
        cpxJob('Pinned', '/usr/bin/php8.3 x.php', $this->site);

        $listed = $this->putJson('/api/php/default', ['default' => '8.3'])
            ->assertOk()
            ->json('cronjobs_on_default_php');

        expect(collect($listed)->pluck('id')->sort()->values()->all())->toBe(collect([$plain->id, $serverPlain->id])->sort()->values()->all())
            ->and($plain->fresh()->command)->toBe('php /home/shopper/artisan schedule:run')
            ->and(collect($this->runs)->filter(fn ($run) => str_contains(implode(' ', $run['command']), 'cron.d'))->all())->toBe([]);
    });
});

describe('Part D: a PHP version a cron job names cannot be removed', function () {

    beforeEach(fn () => Queue::fake());

    it('refuses, naming the jobs', function () {
        // No site uses 8.3 here, so only the cron job stands in the way.
        $this->site->forceFill(['php_version' => '8.4'])->save();
        cpxJob('WP cron', '/usr/bin/php8.3 -f x.php', $this->site);
        cpxJob('Report', 'cd /root && /usr/bin/php8.3 report.php');

        $this->deleteJson('/api/php/versions/8.3')
            ->assertStatus(422)
            ->assertJsonPath('message', 'PHP 8.3 is used by cron jobs: Report, WP cron. Switch them to another version or delete them first.');
    });

    it('is not held up by plain php, and goes ahead once the job is switched', function () {
        $this->site->forceFill(['php_version' => '8.4'])->save();
        $job = cpxJob('WP cron', '/usr/bin/php8.3 -f x.php', $this->site);
        cpxJob('Plain', 'php x.php', $this->site);

        $this->deleteJson('/api/php/versions/8.3')->assertStatus(422);

        $this->postJson("/api/applications/{$this->site->id}/cronjobs/switch-php", ['version' => '8.4', 'cronjob_ids' => [$job->id]])->assertOk();

        $response = $this->deleteJson('/api/php/versions/8.3');
        expect($response->status())->not->toBe(422, (string) $response->getContent());
    });
});

it('records the PHP version and each PHP setting a save changed', function () {
    $this->site->forceFill(['isolated_at' => now()])->save(); // limits need the site's own pool
    $this->putJson("/api/applications/{$this->site->id}/php", ['php_version' => '8.4', 'memory_limit' => '512M'])->assertOk();

    $changes = ActivityLog::where('type', 'application')->where('action', 'php_settings_updated')->latest('id')->value('properties')['changes'];

    expect($changes[0])->toBe(['field' => 'php_version', 'from' => '8.3', 'to' => '8.4'])
        // Never set on this site: the default it ran with, not "none".
        ->and(collect($changes)->firstWhere('field', 'memory_limit'))->toBe(['field' => 'memory_limit', 'from' => '256M', 'to' => '512M']);

    $line = collect($this->getJson('/api/activity-log?filter[type]=application')->json('activity_log'))->firstWhere('action', 'php_settings_updated');
    expect($line['changes'][0]['text'])->toBe('PHP version 8.3 → 8.4')
        ->and(collect($line['changes'])->firstWhere('field', 'memory_limit')['label'])->toBe('memory_limit');
});
