<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Support\OsRelease;

/*
 * What a failed install *says*. `install_failed` is shared by PHP, Node, database engines and
 * fail2ban, so its wording must not name PHP for the others.
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
    // Not persisted: `message()` reads the row only, and the table is unique on
    // (runtime, version, extension), so saving would block comparing two reasons for one engine.
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
        // The catalog's label, not the column's key.
        ->and($message)->toContain('MongoDB')
        ->and($message)->not->toContain('mongodb has')
        // Not this server's fault: the message must not send the reader to audit apt sources.
        ->and($message)->not->toContain('PHP');
});

it('says the OS is unknown rather than printing nothing when os-release is unreadable', function () {
    // "has not published packages for  yet" is worse than admitting the release is unknown.
    osReleaseIs('');

    $message = failedInstall('database', 'mongodb', 'os_unsupported')->message();

    expect($message)->toContain("this server's operating system")
        ->and($message)->not->toContain(' for  ');
});

it('never tells a database install to check the PHP repository', function () {
    // apt's "Unable to locate package" classifies as `package_not_found`.
    $message = failedInstall('database', 'mongodb', 'package_not_found')->message();

    expect($message)->not->toContain('PHP')
        ->and($message)->toContain('MongoDB');
});

it('never tells a Node install to check the PHP repository', function () {
    $message = failedInstall('node', '24.2.0', 'package_not_found')->message();

    expect($message)->not->toContain('PHP')
        ->and($message)->toContain('24.2.0');
});

it('still gives PHP its own wording', function () {
    // The shared wording is domain-neutral; for PHP the repository really is the cause.
    $message = failedInstall('php', '8.4', 'package_not_found')->message();

    expect($message)->toContain('PHP repository')
        ->and($message)->toContain('8.4');
});

it('falls through to the shared group for reasons that do not differ per runtime', function () {
    // Only reasons that really differ get a per-runtime override.
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
    // A removal's terminal status is `failed` too; its reason carries the operation prefix.
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
