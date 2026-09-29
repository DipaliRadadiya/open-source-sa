<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\PanelDirectory;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * `.panel` is where root writes a site's Basic Auth file, PHP ini and staging
 * dumps. It was owned by the site user on every PHP site (PoolManager's
 * `chown -R` on the parent of `sessions`), so a link the user planted there
 * redirected root's writes — reproduced live on 2026-09-29, where enabling
 * Basic Auth wrote into, and chowned to the user, a root-owned file outside
 * the site. PanelDirectory::secure() takes the directory back and removes
 * what was planted; the driver runs it on every resync, which is how existing
 * servers repair themselves.
 */

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/sv-oss-panel-secure-'.getmypid();
    File::deleteDirectory($this->home);

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => $this->home]);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);

    $this->panel = $this->application->panelPath();
});

afterEach(fn () => File::deleteDirectory($this->home));

it('removes links planted in .panel and in the directories root writes into, and nothing else', function () {
    // Real commands against a real directory: the question is what `find`
    // actually matches, which a fake cannot answer. (The chown to root fails
    // here, as the suite is not root — best-effort by design, and not what
    // this test measures.)
    File::makeDirectory("{$this->panel}/php", 0755, true);
    File::makeDirectory("{$this->panel}/trash/20260929-000000/wp-content", 0755, true);
    File::put("{$this->panel}/.htpasswd", "qa:hash\n");

    symlink('/etc/passwd', "{$this->panel}/.htpasswd-planted");
    symlink('/opt', "{$this->panel}/sessions");
    symlink('/etc/hosts', "{$this->panel}/php/zz-panel.ini");
    // A link the user deleted into their own trash is theirs, not planted.
    symlink('../uploads', "{$this->panel}/trash/20260929-000000/wp-content/uploads");

    app(PanelDirectory::class)->secure($this->application->fresh('systemUser'));

    expect(is_link("{$this->panel}/.htpasswd-planted"))->toBeFalse()
        ->and(is_link("{$this->panel}/sessions"))->toBeFalse()
        ->and(is_link("{$this->panel}/php/zz-panel.ini"))->toBeFalse()
        // The real file and the user's own trash are left exactly as they were.
        ->and(File::get("{$this->panel}/.htpasswd"))->toBe("qa:hash\n")
        ->and(is_link("{$this->panel}/trash/20260929-000000/wp-content/uploads"))->toBeTrue()
        // And nothing was followed: the targets are untouched system files.
        ->and(file_exists('/etc/passwd'))->toBeTrue();
});

it('takes .panel and what root writes in it back for root, without following links', function () {
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = implode(' ', $process->command);

        return Process::result();
    });

    app(PanelDirectory::class)->secure($this->application->fresh('systemUser'));

    $panel = $this->panel;

    expect($ran)->toContain("find {$panel} -maxdepth 0 -type d -exec chown -h root:root {} ; -exec chmod 0755 {} ;")
        ->and($ran)->toContain("find {$panel} -mindepth 1 -maxdepth 1 ( ( -name .htpasswd -type f ) -o ( -type d ( -name php -o -name staging-backups -o -name staging-rollbacks ) ) ) -exec chown -h root:root {} +")
        // Nothing in secure() may follow a link: no bare chown/chmod on a
        // path inside `.panel`.
        ->and(collect($ran)->contains(fn (string $c) => str_starts_with($c, 'chown ') || str_starts_with($c, 'chmod ')))->toBeFalse();
});

it('runs on every resync, which is how existing servers repair themselves', function () {
    ServerCapability::query()->delete();
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'source' => 'installer', 'verified_at' => now()]);

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = implode(' ', $process->command);

        return Process::result();
    });

    app(WebServerManager::class)->driver()->ensureDirectories($this->application->fresh('systemUser'));

    expect($ran)->toContain("find {$this->panel} -mindepth 1 -maxdepth 1 -type l -print -delete");
});
