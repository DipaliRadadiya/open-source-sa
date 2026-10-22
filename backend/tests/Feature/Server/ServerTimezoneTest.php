<?php

use App\Support\ServerTimezone;

/*
 * RC-1 (2026-10-20): `timedatectl set-timezone Asia/Kolkata` repoints
 * /etc/localtime and, on Ubuntu 24.04, leaves /etc/timezone saying Etc/UTC
 * (measured on a test server). Read in that order, every cron job said
 * "Etc/UTC" while it ran 5½ hours earlier.
 */
beforeEach(function () {
    $this->tzDir = sys_get_temp_dir().'/sv-oss-tzlink-'.bin2hex(random_bytes(4));
    mkdir($this->tzDir);
    file_put_contents($this->tzDir.'/timezone', "Etc/UTC\n");
    config(['server.timezone_file' => $this->tzDir.'/timezone', 'server.localtime_link' => $this->tzDir.'/localtime']);
    ServerTimezone::forget();
});

afterEach(function () {
    @unlink($this->tzDir.'/localtime');
    @unlink($this->tzDir.'/timezone');
    @rmdir($this->tzDir);
    ServerTimezone::forget();
});

it('follows /etc/localtime when /etc/timezone was left behind', function () {
    symlink('/usr/share/zoneinfo/Asia/Kolkata', $this->tzDir.'/localtime');

    expect(ServerTimezone::get())->toBe('Asia/Kolkata');
});

it('reads a zone under the posix and right trees by its plain name', function (string $target) {
    symlink($target, $this->tzDir.'/localtime');

    expect(ServerTimezone::get())->toBe('Europe/Berlin');
})->with(['/usr/share/zoneinfo/posix/Europe/Berlin', '../usr/share/zoneinfo/right/Europe/Berlin']);

it('falls back to /etc/timezone when /etc/localtime is a plain copy', function () {
    file_put_contents($this->tzDir.'/localtime', 'TZif2 binary zone data');

    expect(ServerTimezone::get())->toBe('Etc/UTC');
});

it('falls back to /etc/timezone when there is no /etc/localtime', function () {
    expect(ServerTimezone::get())->toBe('Etc/UTC');
});

it('says UTC when neither names a zone', function () {
    unlink($this->tzDir.'/timezone');

    expect(ServerTimezone::get())->toBe('UTC');
});

/*
 * OLD-4 (2026-10-22): the zone was memoised for the life of the process, and
 * the queue worker lives for days — a PHP version installed after a timezone
 * change was given the zone the worker first read. No forget() here: that is
 * exactly what a long-lived worker never calls.
 */
it('follows a timezone change in the same process without being told', function () {
    symlink('/usr/share/zoneinfo/Etc/UTC', $this->tzDir.'/localtime');
    expect(ServerTimezone::get())->toBe('Etc/UTC');

    unlink($this->tzDir.'/localtime');
    symlink('/usr/share/zoneinfo/Asia/Kolkata', $this->tzDir.'/localtime');

    expect(ServerTimezone::get())->toBe('Asia/Kolkata');
});

it('follows a rewritten /etc/timezone when there is no link', function () {
    expect(ServerTimezone::get())->toBe('Etc/UTC');

    file_put_contents($this->tzDir.'/timezone', "Europe/Berlin\n");
    touch($this->tzDir.'/timezone', time() + 5);

    expect(ServerTimezone::get())->toBe('Europe/Berlin');
});
