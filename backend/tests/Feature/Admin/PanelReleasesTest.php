<?php

use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelReleases;

/*
 * These render the shell that replaces the panel with itself. They are asserted
 * as text because that is what runs: the update is a detached script that has
 * to survive php-fpm reloading and its own code being swapped, so the steps
 * crossing that moment cannot be PHP.
 */

function releases(): PanelReleases
{
    return new PanelReleases(new PanelLayout('/var/www/panel/current/backend'));
}

it('extracts a tag with no VCS metadata in the release', function () {
    // `git archive` rather than a worktree or clone: the release cannot then be
    // checked out, reset, or left locked by something else, and a corrupted
    // .git cannot take every release down with it.
    $command = releases()->create('/var/www/panel/shared/repo', 'v1.0.2', '/var/www/panel/releases/20260822-093000');

    expect($command)->toContain('archive')
        ->and($command)->toContain("'v1.0.2'")
        // The exception has to travel with the command; systemd-run does not
        // carry root's gitconfig, which is what broke every update before.
        ->and($command)->toContain('safe.directory=');
});

it('links shared paths before anything runs inside the release', function () {
    $commands = releases()->linkShared('/var/www/panel/releases/20260822-093000');

    $env = collect($commands)->first(fn (string $c) => str_contains($c, 'backend/.env'));

    expect($env)->not->toBeNull()
        ->and($env)->toContain("'/var/www/panel/shared/.env'")
        // `ln -s` onto an existing directory creates the link *inside* it, so a
        // storage/ the archive shipped has to go first or the release reads a
        // storage/storage nothing writes to.
        ->and($env)->toContain('rm -rf');
});

it('swaps the symlink atomically rather than unlinking and recreating', function () {
    // `ln -sfn` alone unlinks then creates: between those calls the panel has
    // no `current` at all and every service pointed through it serves nothing.
    // A single rename(2) has no such window.
    $command = releases()->activate('/var/www/panel/releases/20260822-093000');

    expect($command)->toContain('mv -T')
        ->and($command)->toContain('.current.pending')
        ->and($command)->toContain("'/var/www/panel/current'");
});

it('rolls back through the same swap as going forward', function () {
    // Rollback runs when something has already gone wrong. It must not have a
    // failure mode the forward path does not.
    $releases = releases();

    expect($releases->rollback('/var/www/panel/releases/20260822-041500'))
        ->toBe($releases->activate('/var/www/panel/releases/20260822-041500'));
});

it('keeps the newest releases and removes the rest', function () {
    // Two is one rollback. Each release is 600 MB–1 GB with vendor/,
    // node_modules/ and .next/, on a panel built for small VPSes.
    expect(PanelReleases::KEEP)->toBe(2);

    $command = releases()->prune();

    // Newest first, then everything past the limit — so the live release, which
    // is always among the newest, is never a candidate.
    expect($command)->toContain('sort -r')
        ->and($command)->toContain('tail -n +3')
        // -r so an empty releases directory is not an `rm -rf` with no operand.
        ->and($command)->toContain('xargs -r rm -rf')
        // And never the live release. Keeping the newest N assumes the live one
        // is among them, which a rollback makes false — it points `current` at
        // an OLDER release, and the next prune then deletes what is being
        // served. Verified against a real directory, where exactly that
        // happened.
        ->and($command)->toContain('readlink -f')
        ->and($command)->toContain('grep -vxF');
});
