<?php

use App\Services\Server\SudoersFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
 * These run the command against a real directory rather than asserting on
 * strings, because the failure that matters here cannot be seen in a string:
 * whether a bad render leaves the server with the grant it had, or with none.
 *
 * A sudoers file that does not parse takes `sudo` away from every account on
 * the machine, the operator's included, on a box that is usually remote. The
 * previous version of this work had five green assertions over rendered shell
 * and still deleted the live release — the lesson taken from that is to run
 * the thing.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sudoers-test-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->target = $this->dir.'/panel';

    config()->set('server.privilege.sudoers_file', $this->target);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('writes a grant that visudo accepts', function () {
    expect(Artisan::call('panel:sudoers'))->toBe(0)
        ->and(is_file($this->target))->toBeTrue();

    // The real validator, not a regex of mine that agrees with my own
    // renderer. visudo is what sudo itself uses to decide the file is legal.
    $check = Process::run(['/usr/sbin/visudo', '-cqf', $this->target]);

    expect($check->successful())->toBeTrue($check->errorOutput());
});

it('leaves the existing grant untouched when the render does not validate', function () {
    // A server with a stale grant still works. A server with no sudo may not
    // be recoverable without console access, so a failure here must change
    // nothing at all.
    file_put_contents($this->target, "panel ALL=(root) NOPASSWD: /usr/bin/tee\n");

    config()->set('server.privilege.binaries', ['tee']);
    config()->set('server.privilege.wildcards', ["not a path\nDefaults bogus_directive_that_will_not_parse"]);

    expect(Artisan::call('panel:sudoers'))->toBe(1)
        ->and(file_get_contents($this->target))
        ->toBe("panel ALL=(root) NOPASSWD: /usr/bin/tee\n")
        // The temporary file lives in the same directory so the final step is
        // an atomic rename. It must not be left behind: anything in
        // /etc/sudoers.d is read by sudo, valid or not.
        ->and(glob($this->dir.'/*.new'))->toBe([]);
});

it('does nothing when the file is already current', function () {
    Artisan::call('panel:sudoers');
    $written = filemtime($this->target);

    expect(Artisan::call('panel:sudoers'))->toBe(0)
        ->and(Artisan::output())->toContain('up to date')
        ->and(filemtime($this->target))->toBe($written);
});

it('reports drift without writing under --dry-run', function () {
    // The update is not the only caller. An operator wants to know whether
    // this server's grant is stale without changing it — which is the state
    // every server reaches when the panel is updated and install.sh is not
    // re-run.
    expect(Artisan::call('panel:sudoers', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('would be created')
        ->and(is_file($this->target))->toBeFalse();
});

it('prints the file without touching disk, for install.sh', function () {
    expect(Artisan::call('panel:sudoers', ['--print' => true]))->toBe(0);

    $printed = Artisan::output();

    // install.sh redirects this straight into /etc/sudoers.d, so anything the
    // console adds — a banner, an extra newline — ends up in the grant.
    expect($printed)->toBe(app(SudoersFile::class)->render())
        ->and(is_file($this->target))->toBeFalse();
});
