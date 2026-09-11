<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Support\OsRelease;

/*
 * What a failed install *says*.
 *
 * `install_failed` is shared by PHP, Node, database engines and fail2ban, and
 * it was worded for PHP alone — so a failed MongoDB install told the user to
 * "check the PHP repository is configured and reachable": the wrong component,
 * the wrong remedy, on a screen with nothing to do with PHP.
 */

beforeEach(function () {
    $this->osRelease = tempnam(sys_get_temp_dir(), 'osrel');
    config(['server.os_release' => $this->osRelease]);
    OsRelease::flush();
});

afterEach(function () {
    @unlink($this->osRelease);
    OsRelease::flush();
});

function osReleaseIs(string $contents): void
{
    file_put_contents(test()->osRelease, $contents);
    OsRelease::flush();
}

function failedInstall(string $runtime, string $version, string $reason, string $extension = ''): RuntimeInstall
{
    // Not persisted: `message()` reads the row, never the database, and the
    // table is unique on (runtime, version, extension) — so saving would make
    // "the same engine, two reasons" impossible to compare in one test.
    return new RuntimeInstall([
        'runtime' => $runtime,
        'version' => $version,
        'extension' => $extension,
        'status' => InstallStatus::Failed,
        'reason' => $reason,
    ]);
}

it('names the Ubuntu release a database engine has not built for', function () {
    osReleaseIs("NAME=\"Ubuntu\"\nVERSION_ID=\"26.04\"\nVERSION_CODENAME=resolute\n");

    $message = failedInstall('database', 'mongodb', 'os_unsupported')->message();

    expect($message)->toContain('Ubuntu 26.04')
        // The catalog's label, not the column's key: "mongodb has not published
        // packages" is the panel talking to itself.
        ->and($message)->toContain('MongoDB')
        ->and($message)->not->toContain('mongodb has')
        // It is not this server's fault and the message has to say so, or the
        // reader goes auditing apt sources that are correct.
        ->and($message)->not->toContain('PHP');
});

it('says the OS is unknown rather than printing nothing when os-release is unreadable', function () {
    // A message reading "has not published packages for  yet" is worse than one
    // that admits it does not know which release this is.
    osReleaseIs('');

    $message = failedInstall('database', 'mongodb', 'os_unsupported')->message();

    expect($message)->toContain("this server's operating system")
        ->and($message)->not->toContain(' for  ');
});

it('never tells a database install to check the PHP repository', function () {
    // The reported bug, in the exact shape it reached the user: apt said
    // "Unable to locate package", which classifies as `package_not_found`.
    $message = failedInstall('database', 'mongodb', 'package_not_found')->message();

    expect($message)->not->toContain('PHP')
        ->and($message)->toContain('MongoDB');
});

it('never tells a Node install to check the PHP repository', function () {
    // Same one-line bug, a screen nobody had reported yet.
    $message = failedInstall('node', '24.2.0', 'package_not_found')->message();

    expect($message)->not->toContain('PHP')
        ->and($message)->toContain('24.2.0');
});

it('still gives PHP its own wording', function () {
    // The shared group became domain-neutral, so PHP's sentence had to move
    // rather than be softened — a PHP install failing this way really is about
    // the PHP repository, and saying less would be a regression.
    $message = failedInstall('php', '8.4', 'package_not_found')->message();

    expect($message)->toContain('PHP repository')
        ->and($message)->toContain('8.4');
});

it('falls through to the shared group for reasons that do not differ per runtime', function () {
    // Only the reasons that genuinely differ get a per-runtime override; the
    // rest must not need one copy per runtime to render at all.
    expect(failedInstall('database', 'mongodb', 'no_space')->message())
        ->toBe(failedInstall('php', '8.4', 'no_space')->message())
        ->and(failedInstall('database', 'mongodb', 'apt_lock')->message())
        ->not->toBeEmpty();
});

it('falls back to unknown rather than rendering a translation key', function () {
    $message = failedInstall('database', 'mongodb', 'something_nobody_translated')->message();

    expect($message)->not->toContain('runtime.')
        ->and($message)->toContain('Quote the reference');
});

it('keeps reporting a removal as a removal', function () {
    // A removal's terminal status is `failed` too, and its reason carries the
    // operation prefix. The per-runtime lookup must not lose that.
    $message = failedInstall('php', '8.4', 'remove_failed')->message();

    expect($message)->toContain('removed')
        ->and($message)->toContain('8.4');
});

it('says nothing at all while an install is still running', function () {
    $row = new RuntimeInstall([
        'runtime' => 'database', 'version' => 'mongodb', 'extension' => '',
        'status' => InstallStatus::Installing, 'reason' => null,
    ]);

    expect($row->message())->toBeNull();
});
