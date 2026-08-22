<?php

use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelMigration;
use App\Services\Panel\PanelReleases;
use Illuminate\Support\Facades\Process;

/*
 * This one runs the migration for real, against a real checkout on disk.
 *
 * Not out of thoroughness — out of experience. Five green assertions over
 * rendered shell said PanelReleases::prune() was correct; running it deleted
 * the release being served. String assertions cannot see a `mv` that moves the
 * wrong thing, a `find` that skips dotfiles, or a symlink chain that does not
 * resolve. Those are the entire risk surface here, on a command whose job is
 * to rearrange a server that is currently working.
 */

function fakePanel(): string
{
    $root = sys_get_temp_dir().'/panel-migrate-'.bin2hex(random_bytes(4));

    mkdir($root.'/backend/database', 0755, true);
    mkdir($root.'/backend/storage/logs', 0755, true);
    mkdir($root.'/frontend', 0755, true);

    file_put_contents($root.'/backend/.env', "APP_KEY=base64:REAL-KEY-DO-NOT-LOSE\nDB_CONNECTION=sqlite\nDB_DATABASE={$root}/backend/database/database.sqlite\n");
    file_put_contents($root.'/backend/database/database.sqlite', 'THE-REAL-DATABASE');
    file_put_contents($root.'/backend/storage/logs/laravel.log', 'existing logs');
    file_put_contents($root.'/frontend/.env.production', 'NEXT_PUBLIC_API=https://panel.example');
    file_put_contents($root.'/install.sh', '# installer');
    // vendor/ and .next/ stand for the expensive build output the migration
    // must carry across rather than rebuild.
    mkdir($root.'/backend/vendor', 0755, true);
    file_put_contents($root.'/backend/vendor/autoload.php', 'built');

    Process::run(['git', 'init', '-q', $root]);

    return $root;
}

function migrationFor(string $root): PanelMigration
{
    // As a real install has it: install.sh writes this absolute path into
    // .env, and databaseFile() reads the connection rather than guessing.
    config()->set('database.connections.sqlite.database', $root.'/backend/database/database.sqlite');

    $layout = new PanelLayout($root.'/backend');

    return new PanelMigration($layout, new PanelReleases($layout));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/panel-migrate-*') ?: [] as $dir) {
        Process::run(['rm', '-rf', $dir]);
    }
});

it('rearranges a real checkout into the release layout', function () {
    $root = fakePanel();
    config()->set('panel_update.root', $root);

    $migration = migrationFor($root);

    expect($migration->preflight())->toBe([]);

    foreach ($migration->plan('20260822-093000') as $step) {
        // The backup shells out to the real artisan, which this fake tree does
        // not have; every other step is exercised exactly as it would run.
        if (in_array($step['step'], ['backup_database', 'restart_services', 'rewrite_env'], true)) {
            continue;
        }

        foreach ($step['commands'] as $command) {
            $result = Process::timeout(60)->run(['/usr/bin/sh', '-c', $command]);
            expect($result->successful())->toBeTrue("{$step['step']}: {$command}\n".$result->errorOutput());
        }
    }

    $release = $root.'/releases/20260822-093000';

    // PHP caches realpath(), and every path below was a directory when this
    // test created it. Without this the assertions read the tree as it was
    // before the migration and pass or fail for the wrong reason.
    clearstatcache(true);

    // The live symlink points at the release, and the old paths still resolve
    // — which is the whole reason no unit, vhost, pool or cron line had to be
    // rewritten on a working server.
    expect(readlink($root.'/current'))->toBe($release)
        ->and(realpath($root.'/backend'))->toBe($release.'/backend')
        ->and(realpath($root.'/frontend'))->toBe($release.'/frontend');

    // The real .env survived, and it is the shared one — not a copy that would
    // drift, and not a release-local file that would generate an APP_KEY.
    expect(file_get_contents($root.'/shared/.env'))->toContain('REAL-KEY-DO-NOT-LOSE')
        ->and(is_link($release.'/backend/.env'))->toBeTrue()
        ->and(file_get_contents($release.'/backend/.env'))->toContain('REAL-KEY-DO-NOT-LOSE');

    // The database moved rather than being left where a prune would delete it.
    expect(file_get_contents($root.'/shared/database/database.sqlite'))->toBe('THE-REAL-DATABASE')
        ->and(file_get_contents($release.'/backend/database/database.sqlite'))->toBe('THE-REAL-DATABASE');

    // Logs outlive the release they were written in.
    expect(file_get_contents($release.'/backend/storage/logs/laravel.log'))->toBe('existing logs');

    // Carried, not rebuilt — the reason this is cheap on a small VPS.
    expect(is_file($release.'/backend/vendor/autoload.php'))->toBeTrue();

    // The repository left the release, so it has the same shape as one built
    // by `git archive`: code, no VCS metadata. The update fetches from shared.
    expect(is_dir($shared = $root.'/shared/repo/.git'))->toBeTrue()
        ->and(is_dir($release.'/.git'))->toBeFalse();

    $head = Process::run(['git', '-C', $root.'/shared/repo', 'rev-parse', '--is-inside-work-tree']);
    expect(trim($head->output()))->toBe('true');
});

it('moves dotfiles, which a glob would silently leave behind', function () {
    // `mv *` does not match .git or .env. Both would be stranded in the old
    // root: the update would find no repository, and the release would have no
    // env — so the first thing to run in it generates an APP_KEY and every
    // encrypted column becomes unreadable.
    $root = fakePanel();
    config()->set('panel_update.root', $root);

    $plan = collect(migrationFor($root)->plan('20260822-093000'));

    foreach ($plan->firstWhere('step', 'create_layout')['commands'] as $command) {
        Process::timeout(60)->run(['/usr/bin/sh', '-c', $command]);
    }

    foreach ($plan->firstWhere('step', 'move_checkout')['commands'] as $command) {
        Process::timeout(60)->run(['/usr/bin/sh', '-c', $command]);
    }

    expect(is_dir($root.'/releases/20260822-093000/.git'))->toBeTrue()
        ->and(is_file($root.'/releases/20260822-093000/backend/.env'))->toBeTrue()
        // ...and the layout directories it must not swallow are still at the root.
        ->and(is_dir($root.'/releases'))->toBeTrue()
        ->and(is_dir($root.'/shared'))->toBeTrue();
});

it('refuses a panel that has already been migrated', function () {
    $root = fakePanel();
    config()->set('panel_update.root', $root);
    mkdir($root.'/releases', 0755, true);

    expect(migrationFor($root)->preflight())->toContain('already_migrated');
});

it('refuses when there is no env to share', function () {
    $root = fakePanel();
    config()->set('panel_update.root', $root);
    unlink($root.'/backend/.env');

    // Migrating without it hands the release no APP_KEY, and the first artisan
    // call generates one — storage secrets, git tokens and database passwords
    // all become unreadable, with nothing to undo it.
    expect(migrationFor($root)->preflight())->toContain('no_env');
});

it('points the database at shared, not back through the symlinks', function () {
    $root = fakePanel();
    config()->set('panel_update.root', $root);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.driver', 'sqlite');

    $env = migrationFor($root)->environment();

    // Through `current` it would depend on two symlinks the update itself
    // manipulates, and SQLite creates a missing file rather than failing — so
    // a broken link produces an empty panel and a successful-looking update.
    expect($env['DB_DATABASE'])->toBe($root.'/shared/database/database.sqlite')
        ->and($env['DB_DATABASE'])->not->toContain('/current/')
        // Recorded because a migrated panel cannot infer it: its own path
        // resolves through `current` to a release directory.
        ->and($env['PANEL_ROOT'])->toBe($root);
});
