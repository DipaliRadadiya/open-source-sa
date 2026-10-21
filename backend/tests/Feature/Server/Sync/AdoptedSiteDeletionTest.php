<?php

use App\Contracts\WebServerDriver;
use App\Enums\DomainType;
use App\Enums\SyncAction;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Database;
use App\Models\ServerCapability;
use App\Models\SyncItem;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Server\Applications\AdoptedSiteConfig;
use App\Services\Server\Sync\Discoverers\WorkerDiscoverer;
use App\Services\Server\WebServers\VhostParser;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Deleting a site that Server Sync adopted (FS-C22 #14).
 *
 * The panel's own delete knows only the files it writes. An adopted site is
 * served from whatever the server already had — `example.com.conf`, a v7
 * `-le-ssl` sibling, a program in a shared supervisor file, a systemd unit —
 * and those outlived the delete. The rules pinned here:
 *
 *  - "delete with files" is refused, before ANYTHING changes, while a worker
 *    adopted from a shared file or a systemd unit still runs from the site
 *    (Dipali, 2026-10-21: "leave everything untouched");
 *  - an adopted vhost goes only when it is provably this site's alone, and a
 *    config test that fails puts it back exactly;
 *  - everything left behind is named, in the activity log too.
 *
 * Nothing here runs a real command: every process goes to the fake host below.
 */

/** A server, as far as the commands the delete runs can tell. */
class AdoptedDeletionHost
{
    /** @var array<string, string> regular files */
    public static array $files = [];

    /** @var array<string, string> symlink => target, as written */
    public static array $links = [];

    /** @var array<string, array{mode: string, uid: string, gid: string}> what `ls -l` would show; unset = 644 root:root */
    public static array $meta = [];

    /** @var array<int, array<int, string>> */
    public static array $commands = [];

    /** @var array<int, string> commands (space-joined) that fail once */
    public static array $fail = [];

    /** @var array<int, string> directories `find` cannot read */
    public static array $unreadable = [];

    public static bool $configTestFails = false;

    public static function fake(): void
    {
        self::$files = [];
        self::$links = [];
        self::$meta = [];
        self::$commands = [];
        self::$fail = [];
        self::$unreadable = [];
        self::$configTestFails = false;

        Process::fake(fn ($process) => self::answer($process));
    }

    public static function answer($process)
    {
        $args = array_values((array) $process->command);

        if (($args[0] ?? '') === 'sudo') {
            $args = array_values(array_slice($args, 2));
        }

        self::$commands[] = $args;
        $line = implode(' ', $args);

        if (($index = array_search($line, self::$fail, true)) !== false) {
            unset(self::$fail[$index]);

            return Process::result(errorOutput: 'simulated failure', exitCode: 1);
        }

        switch ($args[0] ?? '') {
            case 'find':
                if (in_array('-P', $args, true) && in_array('-name', $args, true)) {
                    $directory = $args[2];

                    if (in_array($directory, self::$unreadable, true)) {
                        return Process::result(errorOutput: 'Permission denied', exitCode: 1);
                    }

                    $path = $directory.'/'.$args[array_search('-name', $args, true) + 1];
                    $type = isset(self::$links[$path]) ? 'l' : (isset(self::$files[$path]) ? 'f' : null);

                    if ($type !== null) {
                        // `-printf` exactly as find does it, for the
                        // directives the panel uses.
                        $meta = self::$meta[$path] ?? ['mode' => $type === 'l' ? '777' : '644', 'uid' => '0', 'gid' => '0'];

                        return Process::result(output: strtr((string) end($args), [
                            '%y' => $type, '%m' => $meta['mode'], '%U' => $meta['uid'], '%G' => $meta['gid'],
                            '%l' => self::$links[$path] ?? '', '\\t' => "\t", '\\n' => "\n",
                        ]));
                    }
                }

                return Process::result();

            case 'cat':
                $path = $args[1];

                if (isset(self::$links[$path])) {
                    $target = self::$links[$path];
                    $path = str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
                    $path = (string) preg_replace('#/[^/]+/\.\./#', '/', $path);
                }

                return isset(self::$files[$path])
                    ? Process::result(output: self::$files[$path])
                    : Process::result(errorOutput: 'No such file or directory', exitCode: 1);

            case 'tee':
                // A new file gets root's umask; an existing one keeps its mode.
                self::$meta[$args[1]] ??= ['mode' => '644', 'uid' => '0', 'gid' => '0'];
                self::$files[$args[1]] = (string) $process->input;

                return Process::result();

            case 'install':
                // install -m MODE /dev/null PATH: an empty file, that mode, root's.
                self::$files[$args[count($args) - 1]] = '';
                self::$meta[$args[count($args) - 1]] = ['mode' => ltrim($args[array_search('-m', $args, true) + 1], '0') ?: '0', 'uid' => '0', 'gid' => '0'];

                return Process::result();

            case 'chown':
                [$uid, $gid] = explode(':', $args[count($args) - 2]);
                self::$meta[$args[count($args) - 1]] = array_merge(self::$meta[$args[count($args) - 1]] ?? ['mode' => '644'], ['uid' => $uid, 'gid' => $gid]);

                return Process::result();

            case 'chmod':
                self::$meta[$args[2]] = array_merge(['mode' => '644', 'uid' => '0', 'gid' => '0'], self::$meta[$args[2]] ?? [], ['mode' => ltrim($args[1], '0') ?: '0']);

                return Process::result();

            case 'ln':
                self::$links[$args[count($args) - 1]] = $args[count($args) - 2];
                self::$meta[$args[count($args) - 1]] = ['mode' => '777', 'uid' => '0', 'gid' => '0'];

                return Process::result();

            case 'rm':
                $target = (string) end($args);
                unset(self::$files[$target], self::$links[$target], self::$meta[$target]);

                if (in_array('-rf', $args, true)) {
                    foreach (array_keys(self::$files) as $path) {
                        if (str_starts_with($path, $target.'/')) {
                            unset(self::$files[$path]);
                        }
                    }
                }

                return Process::result();

            case 'nginx':
            case 'apachectl':
                return self::$configTestFails
                    ? Process::result(errorOutput: 'emerg: test failed', exitCode: 1)
                    : Process::result(errorOutput: 'syntax is ok');
        }

        return Process::result();
    }

    /** Every command, space-joined. */
    public static function lines(): array
    {
        return array_map(fn (array $c): string => implode(' ', $c), self::$commands);
    }

    /** Anything that could have changed the server. */
    public static function mutations(): array
    {
        return array_values(array_filter(self::lines(), fn (string $l): bool => preg_match('/^(rm|tee|ln|install|cp|mv|chattr|chown|chmod|supervisorctl|systemctl|service|kill|userdel|certbot|fail2ban-client|nginx -s|apachectl -k|mysql|psql|docker) ?/', $l) === 1
            || (str_starts_with($l, 'find') && str_contains($l, '-delete'))));
    }
}

const ADOPTED_ROOT = '/home/siteowner/shop/public_html';
const ADOPTED_AVAILABLE = '/etc/nginx/sites-available';
const ADOPTED_ENABLED = '/etc/nginx/sites-enabled';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->owner = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    config([
        'server.web_server_drivers.nginx.sites_dir' => ADOPTED_ENABLED,
        'server.web_server_drivers.nginx.sites_available_dir' => ADOPTED_AVAILABLE,
        'server.web_server_drivers.apache.sites_dir' => '/etc/apache2/sites-enabled',
        'server.web_server_drivers.apache.sites_available_dir' => '/etc/apache2/sites-available',
        'server.applications.supervisor_dir' => '/etc/supervisor/conf.d',
    ]);

    AdoptedDeletionHost::fake();
});

/** A site exactly as Sync adopts one: the folder is the slug, every domain recorded. */
function adoptedSite(array $overrides = [], array $domains = ['example.com', 'www.example.com']): Application
{
    $application = Application::forceCreate(array_merge([
        'system_user_id' => test()->owner->id,
        'name' => 'example.com',
        'slug' => 'shop',
        'domain' => $domains[0],
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'settings' => ['adoption' => ['inferred_site_type' => 'php', 'confidence' => 90, 'matched' => [], 'document_root' => ADOPTED_ROOT, 'proxied_port' => null]],
    ], $overrides));

    foreach ($domains as $index => $domain) {
        $application->domains()->create(['domain' => $domain, 'type' => $index === 0 ? DomainType::Primary : DomainType::Alias]);
    }

    return $application;
}

function syncRun(): SyncRun
{
    return SyncRun::create(['mode' => SyncMode::Apply, 'status' => SyncStatus::Completed, 'started_at' => now(), 'finished_at' => now()]);
}

/** The record Sync writes when it adopts a site — the only thing the delete trusts. */
function recordAdoption(Application $application, string $path, ?SyncRun $run = null, string $root = ADOPTED_ROOT): SyncRun
{
    $run ??= syncRun();

    SyncItem::create([
        'sync_run_id' => $run->id, 'resource_type' => 'application', 'resource_key' => $application->domain,
        'action' => SyncAction::Adopted, 'model_type' => Application::class, 'model_id' => $application->id,
        'evidence' => ['path' => $path, 'document_root' => $root, 'owner' => 'siteowner', 'folder' => $application->slug, 'domains' => ['example.com', 'www.example.com']],
    ]);

    return $run;
}

/** v7's `-le-ssl` file: a second vhost for the same folder, skipped as `folder_taken`. */
function recordSibling(SyncRun $run, string $path, string $folder = 'shop'): void
{
    SyncItem::create([
        'sync_run_id' => $run->id, 'resource_type' => 'application', 'resource_key' => 'example.com',
        'action' => SyncAction::Skipped, 'reason' => 'folder_taken',
        'evidence' => ['path' => $path, 'document_root' => ADOPTED_ROOT, 'folder' => $folder],
    ]);
}

function adoptedNginxVhost(string $names = 'example.com www.example.com', string $root = ADOPTED_ROOT, string $extra = ''): string
{
    return "server {\n    listen 80;\n    server_name {$names};\n    root {$root};\n{$extra}    index index.php;\n}\n";
}

/** A served file: in sites-available, linked from sites-enabled. */
function servedVhost(string $name, string $contents, ?string $linkTarget = null): void
{
    AdoptedDeletionHost::$files[ADOPTED_AVAILABLE.'/'.$name] = $contents;
    AdoptedDeletionHost::$links[ADOPTED_ENABLED.'/'.$name] = $linkTarget ?? ADOPTED_AVAILABLE.'/'.$name;
}

/** A worker Sync adopted, with the record it wrote. */
function adoptedWorker(Application $application, array $evidence, ?string $program = null, string $name = 'queue'): Worker
{
    $worker = Worker::create([
        'application_id' => $application->id, 'name' => $name, 'command' => 'php artisan queue:work --password=hunter2',
        'kind' => 'queue', 'directory' => '/home/siteowner/shop', 'processes' => 1, 'enabled' => false,
    ]);

    if ($program !== null) {
        $worker->forceFill(['program' => $program])->save();
    }

    SyncItem::create([
        'sync_run_id' => syncRun()->id, 'resource_type' => 'worker', 'resource_key' => 'example.com:'.$name,
        'action' => SyncAction::Adopted, 'model_type' => Worker::class, 'model_id' => $worker->id,
        'evidence' => $evidence + ['application' => 'example.com', 'command' => 'php artisan queue:work --password=hunter2', 'already_running_elsewhere' => true],
    ]);

    return $worker;
}

/** Every row of every table a delete could touch, for "nothing changed". */
function databaseSnapshot(): array
{
    $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
        ->reject(fn (string $t): bool => in_array($t, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'], true)
            || str_starts_with($t, 'sqlite_'))
        ->sort()->values();

    return $tables->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->get()->map(fn ($row) => (array) $row)->all()])->all();
}

function deleteSite(Application $application, string $query = 'remove_files=true', array $headers = [])
{
    return test()->actingAs(test()->admin)->withHeaders($headers)->deleteJson("/api/applications/{$application->id}?{$query}");
}

function leftovers(): array
{
    return ActivityLog::query()->where('type', 'application')->where('action', 'adopted_config_left')->orderBy('id')->get()
        ->map(fn (ActivityLog $log): array => [$log->properties['path'], $log->properties['left_reason']])->all();
}

describe('refusing "delete with files" while an adopted worker it cannot prove it owns runs from the site', function () {
    it('refuses for a worker adopted from a shared supervisor file, before changing anything at all', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/apps.conf', 'program' => 'queue', 'program_count' => 3]);
        $before = databaseSnapshot();
        $files = AdoptedDeletionHost::$files;
        $links = AdoptedDeletionHost::$links;

        // Every other destructive flag too: the refusal comes before the
        // databases, the account and the backups are even looked at.
        Database::create(['name' => 'shop_live', 'engine' => 'mysql', 'application_id' => $application->id]);
        $before = databaseSnapshot();

        $response = deleteSite($application, 'remove_files=true&remove_databases=true&remove_system_user=true')
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'adopted_worker_unproven');

        // Named — the worker and the file it lives in — and never its command
        // line, which here carries a password.
        expect($response->json('errors.remove_files.0'))->toContain('"queue"')
            ->toContain('/etc/supervisor/conf.d/apps.conf')
            ->not->toContain(':workers')
            ->not->toContain('hunter2')
            ->and($response->json('message'))->toBe($response->json('errors.remove_files.0'));

        // Not one command: no stop, no disable, no rewrite, no removal — not
        // even of the backups or the panel's own files.
        expect(AdoptedDeletionHost::$commands)->toBe([])
            ->and(AdoptedDeletionHost::$files)->toBe($files)
            ->and(AdoptedDeletionHost::$links)->toBe($links)
            ->and(databaseSnapshot())->toBe($before);
    });

    it('refuses for a worker adopted from a systemd unit', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'systemd', 'path' => '/etc/systemd/system/shop-horizon.service', 'unit' => 'shop-horizon.service'], name: 'shop-horizon');
        $before = databaseSnapshot();

        $response = deleteSite($application)->assertUnprocessable()->assertJsonPath('reason', 'adopted_worker_unproven');

        expect($response->json('errors.remove_files.0'))->toContain('"shop-horizon"')->toContain('/etc/systemd/system/shop-horizon.service')
            ->and(AdoptedDeletionHost::$commands)->toBe([])
            ->and(databaseSnapshot())->toBe($before);
    });

    it('refuses before a stranded install is even marked failed', function () {
        // `StrandedProvisioning::close()` is the first write the delete makes
        // on its own; the refusal comes ahead of it.
        $application = adoptedSite(['status' => 'provisioning', 'provisioning_started_at' => now()->subDays(2)]);
        adoptedWorker($application, ['source' => 'systemd', 'path' => '/etc/systemd/system/shop-horizon.service']);
        $before = databaseSnapshot();

        deleteSite($application)->assertUnprocessable()->assertJsonPath('reason', 'adopted_worker_unproven');

        expect($application->fresh()->status->value)->toBe('provisioning')
            ->and(databaseSnapshot())->toBe($before)
            ->and(AdoptedDeletionHost::$commands)->toBe([]);
    });

    it('never reads an unset program as safe', function () {
        // A supervisor file named after the program, but adopted when the
        // worker already existed: no `program` was recorded, so the panel
        // never proved it.
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/queue.conf', 'program' => 'queue', 'program_count' => 1]);

        deleteSite($application)->assertUnprocessable()->assertJsonPath('reason', 'adopted_worker_unproven');

        expect(Application::find($application->id))->not->toBeNull();
    });

    it('refuses for a canonical program whose file has since become shared, reading only', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-queue.conf', 'program' => 'shop-queue', 'program_count' => 1], 'shop-queue');
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-queue.conf'] = "[program:shop-queue]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/shop\n\n[program:blog-queue]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/blog\n";
        $before = databaseSnapshot();

        $response = deleteSite($application)->assertUnprocessable()->assertJsonPath('reason', 'adopted_worker_unproven');

        expect($response->json('errors.remove_files.0'))->toContain('/etc/supervisor/conf.d/shop-queue.conf')
            ->and(AdoptedDeletionHost::mutations())->toBe([])
            ->and(databaseSnapshot())->toBe($before);
    });

    it('lets a canonical program still in one file inside the site go, and deletes the files', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-queue.conf', 'program' => 'shop-queue', 'program_count' => 1], 'shop-queue');
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-queue.conf'] = "[program:shop-queue]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/shop\n";

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        expect(AdoptedDeletionHost::lines())->toContain('supervisorctl stop shop-queue:*')
            ->toContain('rm -f /etc/supervisor/conf.d/shop-queue.conf')
            ->toContain('rm -rf /home/siteowner/shop')
            ->and(Application::find($application->id))->toBeNull();
    });

    it('does not count a worker the panel created itself', function () {
        $application = adoptedSite();
        Worker::create(['application_id' => $application->id, 'name' => 'queue', 'command' => 'php artisan queue:work', 'kind' => 'queue', 'enabled' => true]);

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        expect(AdoptedDeletionHost::lines())->toContain('rm -rf /home/siteowner/shop');
    });

    it('keeps the plain delete working, and names the worker it leaves running', function () {
        $application = adoptedSite();
        $worker = adoptedWorker($application, ['source' => 'systemd', 'path' => '/etc/systemd/system/shop-horizon.service', 'unit' => 'shop-horizon.service'], name: 'shop-horizon');

        deleteSite($application, 'remove_files=false')->assertOk()->assertExactJson(['deleted' => true]);

        // Only the panel's own program name is addressed; the unit Sync found
        // is never stopped, disabled or edited.
        $lines = AdoptedDeletionHost::lines();
        expect(collect($lines)->filter(fn (string $l): bool => str_contains($l, 'shop-horizon.service') || str_starts_with($l, 'systemctl') && str_contains($l, 'horizon'))->all())->toBe([])
            ->and(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, 'rm -rf'))->all())->toBe([])
            ->and($lines)->toContain('supervisorctl stop sv-worker-'.$worker->slug.':*')
            ->and(leftovers())->toBe([['/etc/systemd/system/shop-horizon.service', 'worker_systemd']]);
    });

    it('keeps 401 and 403 ahead of the refusal', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'systemd', 'path' => '/etc/systemd/system/shop-horizon.service']);
        $viewer = User::factory()->create();
        grantPermission($viewer, 'application');

        $this->deleteJson("/api/applications/{$application->id}?remove_files=true")->assertUnauthorized();
        $this->actingAs($viewer)->deleteJson("/api/applications/{$application->id}?remove_files=true")->assertForbidden();

        expect(AdoptedDeletionHost::$commands)->toBe([]);
    });

    it('explains itself in the reader\'s language', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'systemd', 'path' => '/etc/systemd/system/shop-horizon.service']);

        $message = deleteSite($application, headers: ['Accept-Language' => 'de'])->assertUnprocessable()->json('message');

        expect($message)->toContain('Es wurde nichts gelöscht')->toContain('/etc/systemd/system/shop-horizon.service')->not->toContain(':workers');
    });
});

describe('removing the vhost Sync adopted the site from', function () {
    it('removes it and its link when it is provably this site\'s, before the driver and the files', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        $lines = AdoptedDeletionHost::lines();
        $link = array_search('rm -f '.ADOPTED_ENABLED.'/example.com.conf', $lines, true);
        $file = array_search('rm -f '.ADOPTED_AVAILABLE.'/example.com.conf', $lines, true);
        $test = array_search('nginx -t', $lines, true);

        expect($link)->toBeInt()->and($file)->toBeGreaterThan($link)->and($test)->toBeGreaterThan($file)
            // The driver's own files still go, after; then the site's files.
            ->and(array_search('rm -f '.ADOPTED_AVAILABLE.'/shop.conf', $lines, true))->toBeGreaterThan($test)
            ->and(array_search('rm -rf /home/siteowner/shop', $lines, true))->toBeGreaterThan($test)
            ->and(AdoptedDeletionHost::$files)->not->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(AdoptedDeletionHost::$links)->not->toHaveKey(ADOPTED_ENABLED.'/example.com.conf')
            ->and(leftovers())->toBe([]);
    });

    it('removes the v7 HTTPS sibling recorded in the same run, after checking it too', function () {
        $application = adoptedSite();
        $run = recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        recordSibling($run, ADOPTED_AVAILABLE.'/example.com-le-ssl.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        servedVhost('example.com-le-ssl.conf', adoptedNginxVhost(extra: "    listen 443 ssl;\n"));

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->not->toHaveKey(ADOPTED_AVAILABLE.'/example.com-le-ssl.conf')
            ->and(AdoptedDeletionHost::$links)->not->toHaveKey(ADOPTED_ENABLED.'/example.com-le-ssl.conf')
            ->and(leftovers())->toBe([]);
    });

    it('does not take a sibling from another run, or one for another folder', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        recordSibling(syncRun(), ADOPTED_AVAILABLE.'/example.com-le-ssl.conf');
        $run = SyncItem::query()->where('model_id', $application->id)->first()->run;
        recordSibling($run, ADOPTED_AVAILABLE.'/other-le-ssl.conf', 'other');
        servedVhost('example.com.conf', adoptedNginxVhost());
        servedVhost('example.com-le-ssl.conf', adoptedNginxVhost());
        servedVhost('other-le-ssl.conf', adoptedNginxVhost());

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com-le-ssl.conf')->toHaveKey(ADOPTED_AVAILABLE.'/other-le-ssl.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/other-le-ssl.conf')
            ->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/example.com-le-ssl.conf');
    });

    it('does the same on Apache, following a2ensite\'s relative link', function () {
        ServerCapability::query()->update(['stack' => 'lamp', 'web_server' => 'apache']);
        $application = adoptedSite();
        recordAdoption($application, '/etc/apache2/sites-available/example.com.conf');
        AdoptedDeletionHost::$files['/etc/apache2/sites-available/example.com.conf'] = "<VirtualHost *:80>\n    ServerName example.com\n    ServerAlias www.example.com\n    DocumentRoot ".ADOPTED_ROOT."\n</VirtualHost>\n";
        AdoptedDeletionHost::$links['/etc/apache2/sites-enabled/example.com.conf'] = '../sites-available/example.com.conf';

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::lines())->toContain('rm -f /etc/apache2/sites-enabled/example.com.conf')
            ->toContain('rm -f /etc/apache2/sites-available/example.com.conf')
            ->toContain('apachectl configtest')
            ->and(leftovers())->toBe([]);
    });

    it('changes nothing for a site Sync never adopted', function () {
        $application = adoptedSite(['settings' => null]);
        servedVhost('example.com.conf', adoptedNginxVhost());

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        // No probe, no read, nothing outside the slug's own files.
        expect(collect(AdoptedDeletionHost::lines())->filter(fn (string $l): bool => str_contains($l, ' -P ') || str_contains($l, 'example.com'))->all())->toBe([])
            ->and(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([]);
    });

    it('never guesses when the sync record is gone', function () {
        $application = adoptedSite();
        servedVhost('example.com.conf', adoptedNginxVhost());

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(collect(AdoptedDeletionHost::lines())->filter(fn (string $l): bool => str_contains($l, 'example.com'))->all())->toBe([])
            // There is no path to name, so the log says it, not the activity log.
            ->and(leftovers())->toBe([]);
    });

    it('leaves a file with a similar name that Sync never recorded', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        servedVhost('example.com-old.conf', adoptedNginxVhost());

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com-old.conf')
            ->and(AdoptedDeletionHost::$links)->toHaveKey(ADOPTED_ENABLED.'/example.com-old.conf');
    });

    it('leaves a file that also serves another site', function (string $contents, string $reason) {
        $other = adoptedSite(['name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.org'], ['blog.example.org']);
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', $contents);
        $files = AdoptedDeletionHost::$files;

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files[ADOPTED_AVAILABLE.'/example.com.conf'])->toBe($files[ADOPTED_AVAILABLE.'/example.com.conf'])
            ->and(AdoptedDeletionHost::$links)->toHaveKey(ADOPTED_ENABLED.'/example.com.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', $reason]])
            ->and(Application::find($other->id))->not->toBeNull();
    })->with([
        'another tracked site\'s domain' => [adoptedNginxVhost('example.com www.example.com blog.example.org'), 'other_site'],
        'a domain nobody tracks' => [adoptedNginxVhost('example.com stranger.example.net'), 'not_this_site'],
        'two roots' => [adoptedNginxVhost().adoptedNginxVhost('example.com', '/home/siteowner/legacy/public_html'), 'not_this_site'],
        'a location with its own root' => [adoptedNginxVhost(extra: "    location /acme { root /var/www/html; }\n"), 'not_this_site'],
        'a catch-all name' => [adoptedNginxVhost('example.com _'), 'not_this_site'],
        'a wildcard name' => [adoptedNginxVhost('example.com *.example.com'), 'not_this_site'],
        'an empty name' => [adoptedNginxVhost('example.com ""'), 'not_this_site'],
        'a quoted name the pattern does not see' => [adoptedNginxVhost()."server {\n    listen 80;\n    \"server_name\" stranger.example.net;\n    root ".ADOPTED_ROOT.";\n}\n", 'not_this_site'],
        'a quoted root the pattern does not see' => [adoptedNginxVhost(extra: "    location /x {\n        \"root\" /var/www/other;\n    }\n"), 'not_this_site'],
        'a root outside the site' => [adoptedNginxVhost(root: '/var/www/example'), 'not_this_site'],
    ]);

    it('never removes another site\'s own file, even when Sync recorded it', function () {
        adoptedSite(['name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.org'], ['blog.example.org']);
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/blog.conf');
        servedVhost('blog.conf', adoptedNginxVhost());

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/blog.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/blog.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/blog.conf', 'other_site']]);
    });

    it('leaves the config of a site with another site nested inside it', function () {
        $application = adoptedSite();
        Application::forceCreate([
            'system_user_id' => $this->owner->id, 'name' => 'inner', 'slug' => 'shop/inner', 'domain' => 'inner.example.org',
            'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => '/', 'status' => 'active',
        ]);
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'other_site']]);
    });

    it('refuses a recorded path that is not a plain name in sites-available', function (string $path) {
        $application = adoptedSite();
        recordAdoption($application, $path);
        AdoptedDeletionHost::$files['/etc/nginx/nginx.conf'] = adoptedNginxVhost();

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey('/etc/nginx/nginx.conf')
            ->and(collect(AdoptedDeletionHost::lines())->filter(fn (string $l): bool => str_contains($l, 'nginx.conf') || str_contains($l, '..'))->all())->toBe([])
            ->and(leftovers())->toBe([[$path, 'unexpected_path']]);
    })->with([
        'traversal' => [ADOPTED_AVAILABLE.'/../nginx.conf'],
        'another directory' => ['/etc/nginx/conf.d/example.com.conf'],
        'a doubled slash' => [ADOPTED_AVAILABLE.'//example.com.conf'],
    ]);

    it('leaves a recorded path that is now a symlink', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        AdoptedDeletionHost::$files['/home/siteowner/evil.conf'] = adoptedNginxVhost();
        AdoptedDeletionHost::$links[ADOPTED_AVAILABLE.'/example.com.conf'] = '/home/siteowner/evil.conf';

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$links)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('cat '.ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'not_regular']]);
    });

    it('leaves a file the web server loads through a link it did not expect', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost(), '/etc/nginx/elsewhere/example.com.conf');

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(AdoptedDeletionHost::$links[ADOPTED_ENABLED.'/example.com.conf'])->toBe('/etc/nginx/elsewhere/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'foreign_link']]);
    });

    it('removes an identical copy in sites-enabled, and leaves a different one', function (bool $identical) {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        AdoptedDeletionHost::$files[ADOPTED_AVAILABLE.'/example.com.conf'] = adoptedNginxVhost();
        AdoptedDeletionHost::$files[ADOPTED_ENABLED.'/example.com.conf'] = $identical ? adoptedNginxVhost() : adoptedNginxVhost()."# edited\n";

        deleteSite($application, 'remove_files=false')->assertOk();

        if ($identical) {
            expect(AdoptedDeletionHost::$files)->not->toHaveKey(ADOPTED_ENABLED.'/example.com.conf')->not->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
                ->and(leftovers())->toBe([]);
        } else {
            expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_ENABLED.'/example.com.conf')->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
                ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'foreign_link']]);
        }
    })->with(['identical' => true, 'different' => false]);

    it('leaves what it cannot read', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        AdoptedDeletionHost::$unreadable = [ADOPTED_AVAILABLE];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'unreadable']]);
    });

    it('puts everything back exactly when the config test fails without it, and carries on', function () {
        $application = adoptedSite();
        $run = recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        recordSibling($run, ADOPTED_AVAILABLE.'/example.com-le-ssl.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        servedVhost('example.com-le-ssl.conf', adoptedNginxVhost(extra: "    listen 443 ssl;\n"), '../sites-available/example.com-le-ssl.conf');
        $files = AdoptedDeletionHost::$files;
        $links = AdoptedDeletionHost::$links;
        AdoptedDeletionHost::$configTestFails = true;

        deleteSite($application, 'remove_files=false')->assertOk()->assertExactJson(['deleted' => true]);

        // Same bytes, same link targets — the relative one stays relative.
        expect(collect(AdoptedDeletionHost::$files)->only(array_keys($files))->sortKeys()->all())->toBe(collect($files)->sortKeys()->all())
            ->and(AdoptedDeletionHost::$links[ADOPTED_ENABLED.'/example.com.conf'])->toBe($links[ADOPTED_ENABLED.'/example.com.conf'])
            ->and(AdoptedDeletionHost::$links[ADOPTED_ENABLED.'/example.com-le-ssl.conf'])->toBe('../sites-available/example.com-le-ssl.conf')
            ->and(leftovers())->toBe([
                [ADOPTED_AVAILABLE.'/example.com.conf', 'config_test_failed'],
                [ADOPTED_AVAILABLE.'/example.com-le-ssl.conf', 'config_test_failed'],
            ])
            // The rest of the delete still ran.
            ->and(AdoptedDeletionHost::lines())->toContain('rm -f '.ADOPTED_AVAILABLE.'/shop.conf')
            ->and(Application::find($application->id))->toBeNull();
    });

    it('stops at a link it cannot remove and keeps the file it points at', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        AdoptedDeletionHost::$fail = ['rm -f '.ADOPTED_ENABLED.'/example.com.conf'];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'remove_failed']]);
    });

    it('says so loudly when putting it back fails too', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        AdoptedDeletionHost::$configTestFails = true;
        AdoptedDeletionHost::$fail = ['tee '.ADOPTED_AVAILABLE.'/example.com.conf'];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'restore_failed']]);
    });
});

describe('OpenLiteSpeed', function () {
    beforeEach(function () {
        $driver = Mockery::mock(WebServerDriver::class);
        $driver->shouldReceive('name')->andReturn('openlitespeed');
        $driver->shouldReceive('configPath')->andReturn('/usr/local/lsws/conf/vhosts/shop/vhconf.conf');
        $manager = Mockery::mock(WebServerManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        app()->instance(WebServerManager::class, $manager);
    });

    it('reports a vhost under another name and edits nothing shared', function () {
        $application = adoptedSite();
        recordAdoption($application, '/etc/sv-ols/example/main.conf');
        AdoptedDeletionHost::$files['/etc/sv-ols/example/main.conf'] = 'docRoot '.ADOPTED_ROOT."\nvhDomain example.com\n";

        app(AdoptedSiteConfig::class)->remove($application);

        expect(AdoptedDeletionHost::mutations())->toBe([])
            ->and(leftovers())->toBe([['/etc/sv-ols/example/main.conf', 'ols_shared']]);
    });

    it('leaves a vhost named after the slug to the driver, as before', function () {
        $application = adoptedSite();
        recordAdoption($application, '/usr/local/lsws/conf/vhosts/shop/vhconf.conf');

        app(AdoptedSiteConfig::class)->remove($application);

        expect(AdoptedDeletionHost::$commands)->toBe([])->and(leftovers())->toBe([]);
    });
});

describe('the site\'s workers', function () {
    it('leaves a canonical program edited into a shared file, and still removes the next worker', function () {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-queue.conf', 'program' => 'shop-queue', 'program_count' => 1], 'shop-queue', 'queue');
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-mail.conf', 'program' => 'shop-mail', 'program_count' => 1], 'shop-mail', 'mail');
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-queue.conf'] = "[program:shop-queue]\ndirectory=/home/siteowner/shop\ncommand=x\n[program:other]\ndirectory=/home/other\ncommand=y\n";
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-mail.conf'] = "[program:shop-mail]\ndirectory=/home/siteowner/shop\ncommand=php artisan queue:work\n";

        deleteSite($application, 'remove_files=false')->assertOk();

        $lines = AdoptedDeletionHost::lines();
        expect($lines)->not->toContain('supervisorctl stop shop-queue:*')
            ->not->toContain('rm -f /etc/supervisor/conf.d/shop-queue.conf')
            ->toContain('supervisorctl stop shop-mail:*')
            ->toContain('rm -f /etc/supervisor/conf.d/shop-mail.conf')
            ->and(AdoptedDeletionHost::$files)->toHaveKey('/etc/supervisor/conf.d/shop-queue.conf')
            ->and(leftovers())->toBe([['/etc/supervisor/conf.d/shop-queue.conf', 'worker_shared']]);
    });

    it('leaves a canonical program that now runs somewhere else', function (string $definition) {
        $application = adoptedSite();
        adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-queue.conf', 'program' => 'shop-queue', 'program_count' => 1], 'shop-queue');
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-queue.conf'] = $definition;

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::lines())->not->toContain('rm -f /etc/supervisor/conf.d/shop-queue.conf')
            ->and(leftovers())->toBe([['/etc/supervisor/conf.d/shop-queue.conf', 'not_this_site']]);
    })->with([
        'a sibling folder' => ["[program:shop-queue]\ndirectory=/home/siteowner/shopping\ncommand=x\n"],
        'a climb out' => ["[program:shop-queue]\ndirectory=/home/siteowner/shop/../blog\ncommand=x\n"],
        'a command elsewhere' => ["[program:shop-queue]\ncommand=php /home/siteowner/shopping/artisan queue:work\n"],
    ]);

    it('keeps removing workers after one of them fails', function () {
        $application = adoptedSite();
        $first = Worker::create(['application_id' => $application->id, 'name' => 'first', 'command' => 'x', 'kind' => 'custom', 'enabled' => true]);
        $second = Worker::create(['application_id' => $application->id, 'name' => 'second', 'command' => 'y', 'kind' => 'custom', 'enabled' => true]);
        AdoptedDeletionHost::$fail = ['supervisorctl stop sv-worker-'.$first->slug.':*', 'supervisorctl status sv-worker-'.$first->slug.':*'];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::lines())->toContain('supervisorctl stop sv-worker-'.$second->slug.':*')
            ->toContain('rm -f /etc/supervisor/conf.d/sv-worker-'.$second->slug.'.conf')
            ->not->toContain('rm -f /etc/supervisor/conf.d/sv-worker-'.$first->slug.'.conf');
    });

    it('touches no other site\'s worker or files', function () {
        $other = adoptedSite(['name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.org'], ['blog.example.org']);
        adoptedWorker($other, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/blog-queue.conf', 'program' => 'blog-queue', 'program_count' => 1], 'blog-queue', 'blog-queue');
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());

        deleteSite($application)->assertOk();

        expect(collect(AdoptedDeletionHost::lines())->filter(fn (string $l): bool => str_contains($l, 'blog'))->all())->toBe([]);
    });

    it('refuses on the Workers screen to delete a canonical program file that became shared', function () {
        $application = adoptedSite();
        $worker = adoptedWorker($application, ['source' => 'supervisor', 'path' => '/etc/supervisor/conf.d/shop-queue.conf', 'program' => 'shop-queue', 'program_count' => 1], 'shop-queue');
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/shop-queue.conf'] = "[program:shop-queue]\ndirectory=/home/siteowner/shop\n[program:other]\ndirectory=/home/other\n";

        $this->actingAs($this->admin)->deleteJson("/api/applications/{$application->id}/workers/{$worker->id}")
            ->assertStatus(500)
            ->assertJsonPath('code', 'worker_control_failed');

        expect(Worker::find($worker->id))->not->toBeNull()
            ->and(AdoptedDeletionHost::mutations())->toBe([]);
    });
});

/** v7's own nginx vhost (`config_files/nginx/basic_vhost`), as rendered for this site. */
function v7NginxVhost(string $listen = "listen 80;\n        listen [::]:80;"): string
{
    return <<<CONF
    server {
            {$listen}

            server_name example.com www.example.com;
            root /home/siteowner/shop/public_html;

            index index.php index.html;

            include snippets/lets-encrypt-alias.conf;

            access_log /home/siteowner/shop/logs/access.log;
            error_log /home/siteowner/shop/logs/error.log;

            location / {
                    try_files \$uri \$uri/ /index.php?\$args;
            }

            location ~ /\.(?!well-known).* {
                    deny all;
            }

            location ~ \.php$ {
                    include fastcgi.conf;
                    fastcgi_intercept_errors on;
                    fastcgi_pass unix:/run/php/shop.sock;
                    fastcgi_read_timeout 300;
            }

            location ~* \.(?:css|js|mjs|jsx|ts|tsx)$ {
                    expires 7d;
                    add_header Cache-Control "public, immutable";
                    try_files \$uri \$uri/ /index.php\$is_args\$args;
            }

            include /home/siteowner/shop/conf/nginx/*.conf;
    }
    CONF;
}

/** v7's own Apache vhost (`config_files/apache2/basic_vhost`), as rendered for this site. */
function v7ApacheVhost(string $open = '<VirtualHost *:80>', string $extra = ''): string
{
    return <<<CONF
    {$open}
    	ServerAdmin support@example.com
    	ServerName example.com
    	ServerAlias example.com www.example.com
    	DocumentRoot /home/siteowner/shop/public_html

    	ErrorLog /home/siteowner/shop/logs/error.log
    	CustomLog /home/siteowner/shop/logs/access.log combined

    	<Directory />
    		Options FollowSymLinks
    		AllowOverride None
    	</Directory>

    	<Directory /home/siteowner/shop/public_html>
    		Options -Indexes +FollowSymLinks +MultiViews
    		AllowOverride All
    		Require all granted
    	</Directory>

    	<IfModule proxy_fcgi_module>
    		<FilesMatch \.php$>
    			SetHandler "proxy:unix:/run/php/shop.sock|fcgi://shop/"
    		</FilesMatch>
    	</IfModule>

    	SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1
    {$extra}
    	IncludeOptional /home/siteowner/shop/conf/apache/*
    </VirtualHost>

    CONF;
}

function useApache(): void
{
    ServerCapability::query()->update(['stack' => 'lamp', 'web_server' => 'apache']);
}

/** Recorded, served and linked the way a2ensite links it. */
function servedApacheVhost(Application $application, string $contents): void
{
    recordAdoption($application, '/etc/apache2/sites-available/example.com.conf');
    AdoptedDeletionHost::$files['/etc/apache2/sites-available/example.com.conf'] = $contents;
    AdoptedDeletionHost::$links['/etc/apache2/sites-enabled/example.com.conf'] = '../sites-available/example.com.conf';
}

describe('a vhost that may serve more than this site', function () {
    // The domains and the root can be exactly this site's while the file
    // still does something for somebody else: a second server block with no
    // name answers every request nothing else claims on its port, an include
    // pulls in whatever another file says, an alias serves another folder.
    // None of that is provably this site's, so none of it is removed.
    it('leaves an nginx file whose domains and root are this site\'s but which does more', function (string $contents) {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', $contents);
        $files = AdoptedDeletionHost::$files;
        $links = AdoptedDeletionHost::$links;

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        expect(AdoptedDeletionHost::$files[ADOPTED_AVAILABLE.'/example.com.conf'])->toBe($files[ADOPTED_AVAILABLE.'/example.com.conf'])
            ->and(AdoptedDeletionHost::$links[ADOPTED_ENABLED.'/example.com.conf'])->toBe($links[ADOPTED_ENABLED.'/example.com.conf'])
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/example.com.conf')
            ->not->toContain('rm -f '.ADOPTED_ENABLED.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'unprovable_config']])
            // The rest of the delete still ran.
            ->and(AdoptedDeletionHost::lines())->toContain('rm -rf /home/siteowner/shop')
            ->and(Application::find($application->id))->toBeNull();
    })->with([
        'a second server block with no name' => [adoptedNginxVhost()."server {\n    listen 8080;\n}\n"],
        'a second unnamed block for the same root' => [adoptedNginxVhost()."server {\n    listen 8080;\n    root ".ADOPTED_ROOT.";\n}\n"],
        'an unnamed block on one line' => [adoptedNginxVhost().'server { listen 8080; return 444; }'],
        'the default server' => [adoptedNginxVhost(extra: "    listen 443 ssl default_server;\n")],
        'the default server, old spelling' => [str_replace('listen 80;', 'listen 80 default;', adoptedNginxVhost())],
        'an include of another file' => [adoptedNginxVhost(extra: "    include /etc/nginx/conf.d/foreign.conf;\n")],
        'a relative include of another snippet' => [adoptedNginxVhost(extra: "    include sites-enabled/blog.conf;\n")],
        'an include out of the site through ..' => [adoptedNginxVhost(extra: "    include /home/siteowner/shop/../blog/conf/nginx/*.conf;\n")],
        'an include of a neighbour with the same prefix' => [adoptedNginxVhost(extra: "    include /home/siteowner/shop2/conf/nginx/*.conf;\n")],
        'an include inside a location' => [adoptedNginxVhost(extra: "    location /x {\n        include /etc/nginx/conf.d/foreign.conf;\n    }\n")],
        'an include outside any server block' => ["include /etc/nginx/conf.d/foreign.conf;\n".adoptedNginxVhost()],
        'an upstream other files may use' => ["upstream shared_php {\n    server 127.0.0.1:9000;\n}\n".adoptedNginxVhost()],
        'a map other files may use' => ["map \$http_upgrade \$connection_upgrade {\n    default upgrade;\n}\n".adoptedNginxVhost()],
        'an alias to another folder' => [adoptedNginxVhost(extra: "    location /media/ {\n        alias /var/www/shared/media/;\n    }\n")],
        'an alias out of the site through ..' => [adoptedNginxVhost(extra: "    location /b/ {\n        alias /home/siteowner/shop/../blog/;\n    }\n")],
        'a quoted directive name discovery\'s pattern does not see' => [adoptedNginxVhost()."server {\n    listen 8080;\n    \"server_name\" example.com;\n    \"include\" /etc/nginx/conf.d/foreign.conf;\n}\n"],
        'a server block hidden after a quoted #' => [adoptedNginxVhost(extra: '    add_header X-Note "#"; } server { listen 8080; root '.ADOPTED_ROOT.";\n")],
        'an unbalanced file' => [adoptedNginxVhost()."}\n"],
        'an unterminated quote' => [adoptedNginxVhost(extra: "    add_header X \"open;\n")],
    ]);

    it('leaves an Apache file whose names and root are this site\'s but which does more', function (string $contents) {
        useApache();
        $application = adoptedSite();
        servedApacheVhost($application, $contents);
        $original = AdoptedDeletionHost::$files['/etc/apache2/sites-available/example.com.conf'];

        deleteSite($application)->assertOk()->assertExactJson(['deleted' => true]);

        expect(AdoptedDeletionHost::$files['/etc/apache2/sites-available/example.com.conf'])->toBe($original)
            ->and(AdoptedDeletionHost::$links['/etc/apache2/sites-enabled/example.com.conf'])->toBe('../sites-available/example.com.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f /etc/apache2/sites-available/example.com.conf')
            ->not->toContain('rm -f /etc/apache2/sites-enabled/example.com.conf')
            ->and(leftovers())->toBe([['/etc/apache2/sites-available/example.com.conf', 'unprovable_config']]);
    })->with([
        'a second VirtualHost with no ServerName' => [v7ApacheVhost()."<VirtualHost *:8080>\n    DocumentRoot ".ADOPTED_ROOT."\n</VirtualHost>\n"],
        'a VirtualHost with only an alias' => [v7ApacheVhost()."<VirtualHost *:8080>\n    ServerAlias example.com\n    DocumentRoot ".ADOPTED_ROOT."\n</VirtualHost>\n"],
        'the _default_ VirtualHost' => [v7ApacheVhost('<VirtualHost _default_:80>')],
        'an Include of another file' => [v7ApacheVhost(extra: "\tInclude /etc/apache2/conf-available/foreign.conf\n")],
        'an IncludeOptional out of the site through ..' => [v7ApacheVhost(extra: "\tIncludeOptional /home/siteowner/shop/../blog/conf/apache/*\n")],
        'an Include outside any VirtualHost' => ["Include /etc/apache2/conf-available/foreign.conf\n".v7ApacheVhost()],
        'a server-wide directive outside any VirtualHost' => ["Listen 8080\n".v7ApacheVhost()],
        'an Alias to another folder' => [v7ApacheVhost(extra: "\tAlias /media /var/www/shared/media\n")],
        'a ScriptAlias to another folder' => [v7ApacheVhost(extra: "\tScriptAlias /cgi-bin/ /usr/lib/cgi-bin/\n")],
        'an AliasMatch out of the site' => [v7ApacheVhost(extra: "\tAliasMatch ^/b/(.*)$ \"/home/siteowner/blog/\$1\"\n")],
        'a macro from elsewhere' => [v7ApacheVhost(extra: "\tUse SharedSite blog.example.org\n")],
        'an unbalanced file' => [v7ApacheVhost()."</VirtualHost>\n"],
    ]);

    it('still removes the configs v7 and certbot write, includes and all', function (string $driver, string $contents) {
        if ($driver === 'apache') {
            useApache();
        }

        $application = adoptedSite();

        $driver === 'apache' ? servedApacheVhost($application, $contents) : (function () use ($application, $contents) {
            recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
            servedVhost('example.com.conf', $contents);
        })();

        deleteSite($application)->assertOk();

        $available = $driver === 'apache' ? '/etc/apache2/sites-available' : ADOPTED_AVAILABLE;

        expect(AdoptedDeletionHost::$files)->not->toHaveKey($available.'/example.com.conf')
            ->and(AdoptedDeletionHost::lines())->toContain('rm -f '.$available.'/example.com.conf')
            ->and(leftovers())->toBe([]);
    })->with([
        'v7 nginx' => ['nginx', v7NginxVhost()],
        'v7 nginx HTTPS' => ['nginx', v7NginxVhost("listen   443 ssl http2;\n        listen   [::]:443 ssl http2;")."\n"],
        'v7 nginx force-https' => ['nginx', "server {\n    listen 80;\n    listen [::]:80;\n\n    server_name example.com www.example.com;\n\n\tinclude snippets/lets-encrypt-alias.conf;\n\n    return 301 https://\$host\$request_uri;\n}\n".v7NginxVhost('listen 443 ssl http2;')],
        'certbot nginx' => ['nginx', "server {\n    server_name example.com www.example.com;\n    root ".ADOPTED_ROOT.";\n    location ~ \\.php\$ {\n        include snippets/fastcgi-php.conf;\n        fastcgi_pass unix:/run/php/shop.sock;\n    }\n    listen 443 ssl; # managed by Certbot\n    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem; # managed by Certbot\n    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot\n    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; # managed by Certbot\n}\nserver {\n    if (\$host = www.example.com) {\n        return 301 https://\$host\$request_uri;\n    } # managed by Certbot\n\n    listen 80;\n    server_name example.com www.example.com;\n    return 404; # managed by Certbot\n}\n"],
        'nginx with an alias inside the site' => ['nginx', adoptedNginxVhost(extra: "    location /media/ {\n        alias /home/siteowner/shop/media/;\n    }\n    add_header X-Note \"a # not a comment\";\n    set \$x \"\${host}-x\";\n")],
        'v7 Apache' => ['apache', v7ApacheVhost()],
        'v7 Apache HTTPS' => ['apache', v7ApacheVhost('<VirtualHost *:443>', "\tSSLEngine on\n\tSSLCertificateFile /etc/letsencrypt/live/example.com/fullchain.pem\n")],
        'certbot Apache' => ['apache', "<IfModule mod_ssl.c>\n".v7ApacheVhost('<VirtualHost *:443>', "\tInclude /etc/letsencrypt/options-ssl-apache.conf\n")."</IfModule>\n"],
        'Apache with an Alias inside the site and a comment' => ['apache', "# managed by hand\n".v7ApacheVhost(extra: "\tAlias /media \"/home/siteowner/shop/media\"\n\t# Include /etc/apache2/conf-available/foreign.conf\n")],
    ]);

    it('puts a removed file back with its own mode and owner, never readable by others in between', function () {
        $application = adoptedSite();
        $run = recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        recordSibling($run, ADOPTED_AVAILABLE.'/example.com-le-ssl.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        // A copy in sites-enabled rather than a link, and a hand-set mode on each.
        AdoptedDeletionHost::$files[ADOPTED_AVAILABLE.'/example.com-le-ssl.conf'] = adoptedNginxVhost(extra: "    listen 443 ssl;\n");
        AdoptedDeletionHost::$files[ADOPTED_ENABLED.'/example.com-le-ssl.conf'] = adoptedNginxVhost(extra: "    listen 443 ssl;\n");
        AdoptedDeletionHost::$meta[ADOPTED_AVAILABLE.'/example.com.conf'] = ['mode' => '640', 'uid' => '0', 'gid' => '4'];
        AdoptedDeletionHost::$meta[ADOPTED_AVAILABLE.'/example.com-le-ssl.conf'] = ['mode' => '600', 'uid' => '0', 'gid' => '0'];
        AdoptedDeletionHost::$meta[ADOPTED_ENABLED.'/example.com-le-ssl.conf'] = ['mode' => '2640', 'uid' => '33', 'gid' => '33'];
        AdoptedDeletionHost::$meta[ADOPTED_ENABLED.'/example.com.conf'] = ['mode' => '777', 'uid' => '0', 'gid' => '4'];
        $files = AdoptedDeletionHost::$files;
        $links = AdoptedDeletionHost::$links;
        $meta = AdoptedDeletionHost::$meta;
        AdoptedDeletionHost::$configTestFails = true;

        deleteSite($application, 'remove_files=false')->assertOk();

        $lines = AdoptedDeletionHost::lines();
        $path = ADOPTED_AVAILABLE.'/example.com.conf';
        $create = array_search("install -m 0600 -o 0 -g 0 /dev/null {$path}", $lines, true);

        expect(collect(AdoptedDeletionHost::$files)->only(array_keys($files))->sortKeys()->all())->toBe(collect($files)->sortKeys()->all())
            ->and(AdoptedDeletionHost::$links)->toBe($links)
            ->and(collect(AdoptedDeletionHost::$meta)->only(array_keys($meta))->sortKeys()->all())->toBe(collect($meta)->sortKeys()->all())
            // Created private, filled, then given back its owner and mode —
            // owner first, because chown clears a setgid bit.
            ->and($create)->toBeInt()
            ->and(array_search("tee {$path}", $lines, true))->toBeGreaterThan($create)
            ->and(array_search("chown 0:4 {$path}", $lines, true))->toBeGreaterThan(array_search("tee {$path}", $lines, true))
            ->and(array_search("chmod 640 {$path}", $lines, true))->toBeGreaterThan(array_search("chown 0:4 {$path}", $lines, true))
            ->and($lines)->toContain('chmod 2640 '.ADOPTED_ENABLED.'/example.com-le-ssl.conf')
            ->toContain('chown 33:33 '.ADOPTED_ENABLED.'/example.com-le-ssl.conf')
            ->toContain('chown -h 0:4 '.ADOPTED_ENABLED.'/example.com.conf')
            // A link is never chmod-ed: that would change the file it points at.
            ->not->toContain('chmod 777 '.ADOPTED_ENABLED.'/example.com.conf')
            ->and(leftovers())->toBe([
                [ADOPTED_AVAILABLE.'/example.com.conf', 'config_test_failed'],
                [ADOPTED_AVAILABLE.'/example.com-le-ssl.conf', 'config_test_failed'],
            ]);
    });

    it('calls a restore that could not put the mode or owner back a failed restore', function (string $step) {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        AdoptedDeletionHost::$meta[ADOPTED_AVAILABLE.'/example.com.conf'] = ['mode' => '640', 'uid' => '0', 'gid' => '4'];
        AdoptedDeletionHost::$configTestFails = true;
        AdoptedDeletionHost::$fail = [$step];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'restore_failed']]);
    })->with([
        'install -m 0600 -o 0 -g 0 /dev/null '.ADOPTED_AVAILABLE.'/example.com.conf',
        'chown 0:4 '.ADOPTED_AVAILABLE.'/example.com.conf',
        'chmod 640 '.ADOPTED_AVAILABLE.'/example.com.conf',
        'chown -h 0:0 '.ADOPTED_ENABLED.'/example.com.conf',
    ]);

    it('removes nothing whose mode and owner it could not read', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost());
        AdoptedDeletionHost::$meta[ADOPTED_AVAILABLE.'/example.com.conf'] = ['mode' => '', 'uid' => 'x', 'gid' => '0'];

        deleteSite($application, 'remove_files=false')->assertOk();

        expect(AdoptedDeletionHost::$files)->toHaveKey(ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(AdoptedDeletionHost::lines())->not->toContain('rm -f '.ADOPTED_AVAILABLE.'/example.com.conf')
            ->and(leftovers())->toBe([[ADOPTED_AVAILABLE.'/example.com.conf', 'unreadable']]);
    });
});

describe('the activity log entry for a leftover', function () {
    it('reads as a sentence with its reason, in the reader\'s language', function () {
        $application = adoptedSite();
        recordAdoption($application, ADOPTED_AVAILABLE.'/example.com.conf');
        servedVhost('example.com.conf', adoptedNginxVhost(), '/etc/nginx/elsewhere/example.com.conf');
        deleteSite($application, 'remove_files=false')->assertOk();

        foreach (['en' => 'the web server loads it through a link', 'de' => 'der Webserver lädt es über einen Link'] as $locale => $words) {
            $row = collect($this->actingAs($this->admin)->withHeaders(['Accept-Language' => $locale])
                ->getJson('/api/server/activity-log?per_page=100')->assertOk()->json('activity_log'))
                ->firstWhere('action', 'adopted_config_left');

            expect($row['description'])->toContain(ADOPTED_AVAILABLE.'/example.com.conf')->toContain('example.com')->toContain($words)
                ->not->toContain(':left_reason')->not->toContain('adopted_left_')->not->toContain(':path');
        }
    });

    it('has a word for every reason in every locale', function () {
        $reasons = ['unexpected_path', 'other_site', 'not_this_site', 'not_regular', 'foreign_link', 'unreadable', 'config_test_failed', 'remove_failed', 'restore_failed', 'ols_shared', 'worker_shared', 'worker_systemd', 'unprovable_config'];

        foreach (['en', 'de', 'es', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
            $values = require lang_path("{$locale}/activity_values.php");

            foreach ($reasons as $reason) {
                expect($values)->toHaveKey('adopted_left_'.$reason);
            }

            $sentence = (require lang_path("{$locale}/activity.php"))['application.adopted_config_left'];
            expect($sentence)->toContain(':path')->toContain(':name')->toContain(':left_reason');
            expect((require lang_path("{$locale}/errors/application.php"))['delete_files_adopted_worker'])->toContain(':workers');
        }
    });
});

describe('which sites Sync gives workers to', function () {
    beforeEach(function () {
        AdoptedDeletionHost::$files['/etc/supervisor/conf.d/site-queue.conf'] = "[program:site-queue]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/shop\n";
        Process::fake(function ($process) {
            $args = (array) $process->command;

            if ($args[0] === 'find' && $args[1] === '/etc/supervisor/conf.d') {
                return Process::result(output: "/etc/supervisor/conf.d/site-queue.conf\n");
            }

            if ($args[0] === 'cat' && isset(AdoptedDeletionHost::$files[$args[1]])) {
                return Process::result(output: AdoptedDeletionHost::$files[$args[1]]);
            }

            return Process::result(exitCode: 1);
        });
    });

    it('attributes a worker only to a site that has a Workers screen', function (string $type, string $profile, bool $attributed) {
        adoptedSite(['site_type' => $type, 'serving_profile' => $profile]);

        expect(app(WorkerDiscoverer::class)->discover(syncRun()))->toHaveCount($attributed ? 1 : 0);
    })->with([
        'plain static' => ['static', 'static', false],
        'git rendered static' => ['git', 'static', true],
        'php' => ['php', 'php', true],
        'wordpress' => ['wordpress', 'php', false],
    ]);

    it('changes nothing about workers already adopted on such a site', function () {
        $application = adoptedSite(['site_type' => 'static', 'serving_profile' => 'static']);
        $worker = Worker::create(['application_id' => $application->id, 'name' => 'old', 'command' => 'x', 'kind' => 'custom', 'enabled' => false]);

        app(WorkerDiscoverer::class)->discover(syncRun());

        expect(Worker::find($worker->id))->not->toBeNull();
    });
});

describe('reading a vhost', function () {
    it('reads what discovery always read', function (string $contents, array $domains, ?string $root, ?int $port) {
        $parsed = app(VhostParser::class)->parse($contents);

        expect($parsed['domains'])->toBe($domains)->and($parsed['root'])->toBe($root)->and($parsed['port'])->toBe($port);
    })->with([
        'one-line nginx' => ['server { listen 80; server_name A.example.com www.a.example.com; root /home/u/a/public_html; }', ['a.example.com', 'www.a.example.com'], '/home/u/a/public_html', null],
        'a commented root' => ["server {\n  # root /wrong;\n  server_name a.test;\n  root /right;\n}", ['a.test'], '/right', null],
        'apache' => ["<VirtualHost *:80>\nServerName a.test\nServerAlias b.test c.test\nDocumentRoot \"/var/www/a\"\n</VirtualHost>", ['a.test', 'b.test', 'c.test'], '/var/www/a', null],
        'openlitespeed' => ["docRoot \$VH_ROOT/public_html\nvhDomain a.test, b.test\nvhAliases c.test", ['a.test', 'b.test', 'c.test'], '$VH_ROOT/public_html', null],
        'node proxy' => ['server { server_name n.test; root /x; location / { proxy_pass http://127.0.0.1:50241; } }', ['n.test'], '/x', 50241],
        'catch-all and wildcard dropped' => ['server { server_name _ *.w.test w.test; root /w; }', ['w.test'], '/w', null],
    ]);

    it('also names every root and every name it cannot own', function () {
        $parsed = app(VhostParser::class)->parse('server { server_name a.test _ *.a.test; root /a/; location /x { root /b; } }');

        expect($parsed['roots'])->toBe(['/a', '/b'])->and($parsed['unowned_names'])->toBe(['_', '*.a.test']);
    });
});
