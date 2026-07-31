<?php

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Exceptions\Server\WebServer\OlsListenerNotFoundException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\PhpOverview;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\OlsSharedConfig;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * OpenLiteSpeed support.
 *
 * Everything here is verified against the *shape* of OLS's configuration, not
 * against a running OLS server — there was none available. So these prove the
 * dangerous logic (rebuilding a file every site depends on, and the order in
 * which files and shared entries change) and deliberately do not claim the
 * paths are right. See the class docblocks.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'ols',
        'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->lsws = sys_get_temp_dir().'/sv-oss-lsws-'.getmypid();
    File::deleteDirectory($this->lsws);
    File::makeDirectory($this->lsws.'/lsphp84/etc/php/8.4/litespeed', 0755, true);

    config([
        'server.php_stacks.lsphp.dir' => $this->lsws,
        'server.web_server_drivers.openlitespeed.shared_config' => '/usr/local/lsws/conf/httpd_config.conf',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->lsws));

/**
 * A shared config with content the panel does not own, on both sides of where
 * its regions go.
 */
function olsConfig(string $managed = ''): string
{
    return <<<CONF
    serverName                SomeServer
    user                      nobody

    listener Default {
      address                 *:80
      secure                  0
    {$managed}}

    # A hand-written virtual host the operator added themselves.
    virtualHost legacy {
      configFile              \$SERVER_ROOT/conf/vhosts/legacy/vhconf.conf
    }
    CONF;
}

/**
 * A fake filesystem keyed by path.
 *
 * Keyed deliberately: `apply()` writes the vhost file *and* the shared config,
 * and a fake that returned "the last thing written" for any `cat` would hand
 * the driver its own vhost back when it went to read httpd_config.conf.
 */
function fakeOls(string $config, bool $testPasses = true): ArrayObject
{
    $runs = new ArrayObject;
    $files = new ArrayObject([sharedPath() => $config]);

    Process::fake(function ($process) use ($runs, $files, $testPasses) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];
        $command = $process->command;
        $path = (string) ($command[1] ?? '');

        if (($command[0] ?? '') === 'cat') {
            return Process::result(output: $files[$path] ?? '');
        }

        if (($command[0] ?? '') === 'tee') {
            $files[$path] = (string) $process->input;

            return Process::result(output: '');
        }

        if (($command[0] ?? '') === 'cp') {
            $files[(string) $command[3]] = $files[(string) $command[2]] ?? '';

            return Process::result(output: '');
        }

        if (str_contains((string) ($command[0] ?? ''), 'lswsctrl')) {
            return $testPasses
                ? Process::result(output: 'ok')
                : Process::result(output: '', errorOutput: 'config error', exitCode: 1);
        }

        return Process::result(output: '');
    });

    test()->files = $files;

    return $runs;
}

function sharedPath(): string
{
    return '/usr/local/lsws/conf/httpd_config.conf';
}

function sharedConfig(): string
{
    return test()->files[sharedPath()] ?? '';
}

describe('the shared httpd_config.conf', function () {
    it('registers a site as a virtualHost block and a listener map', function () {
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test', 'www.shop.test']);

        expect(sharedConfig())
            ->toContain('virtualHost shop.test {')
            ->toContain('map                     shop.test shop.test, www.shop.test');
    });

    it('never touches anything outside its markers', function () {
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test']);

        // Users migrate real servers into this panel with hand-written config
        // in this file. Losing it would be unforgivable.
        expect(sharedConfig())
            ->toContain('# A hand-written virtual host the operator added themselves.')
            ->toContain('virtualHost legacy {')
            ->toContain('serverName                SomeServer')
            ->toContain('address                 *:80');
    });

    it('puts the map inside the listener block, where it is legal', function () {
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test']);

        $config = sharedConfig();
        $listener = strpos($config, 'listener Default {');
        $map = strpos($config, 'map                     shop.test');
        $close = strpos($config, "\n}", $listener);

        expect($map)->toBeGreaterThan($listener)->toBeLessThan($close);
    });

    it('is idempotent — registering twice leaves one block, not two', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test']);
        $shared->register('shop.test', ['shop.test']);

        // The region is rebuilt rather than appended to, so a re-provision
        // cannot duplicate a site.
        expect(substr_count(sharedConfig(), 'virtualHost shop.test {'))->toBe(1);
    });

    it('keeps other managed sites when one is removed', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test']);
        $shared->register('blog.test', ['blog.test']);
        $shared->unregister('shop.test');

        expect(sharedConfig())
            ->not->toContain('virtualHost shop.test {')
            ->not->toContain('map                     shop.test')
            ->toContain('virtualHost blog.test {')
            ->toContain('map                     blog.test');
    });

    it('removes both the block and the map, not just one', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test']);
        $shared->unregister('shop.test');

        // A leftover map pointing at a gone virtualHost fails config_test and
        // blocks every later reload — for every site, not just this one.
        expect(sharedConfig())->not->toContain('shop.test');
    });

    it('restores the whole file when the config test fails', function () {
        $runs = fakeOls(olsConfig(), testPasses: false);

        $result = app(OlsSharedConfig::class)->register('shop.test', ['shop.test']);

        $commands = collect($runs)->pluck('command');

        expect($result->failed())->toBeTrue()
            // Backed up before writing, and put back after the test failed. A
            // broken shared config surviving to the next reload takes the
            // whole box down.
            ->and($commands)->toContain(['cp', '-f', '/usr/local/lsws/conf/httpd_config.conf', '/usr/local/lsws/conf/httpd_config.conf.panel-bak'])
            ->and($commands)->toContain(['cp', '-f', '/usr/local/lsws/conf/httpd_config.conf.panel-bak', '/usr/local/lsws/conf/httpd_config.conf']);
    });

    it('does not write at all when nothing would change', function () {
        $runs = fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test']);
        $runs->exchangeArray([]);
        $shared->register('shop.test', ['shop.test']);

        // No reason to rewrite a file every site depends on to say the same
        // thing again.
        expect(collect($runs)->pluck('command')->map(fn ($c) => $c[0] ?? ''))
            ->not->toContain('tee');
    });

    it('refuses when there is no listener to map into', function () {
        fakeOls("serverName Nothing\nuser nobody\n");

        // Inventing a listener means guessing an address and port — either a
        // no-op or a hijack of one something else owns.
        expect(fn () => app(OlsSharedConfig::class)->register('shop.test', ['shop.test']))
            ->toThrow(OlsListenerNotFoundException::class);
    });
});

describe('the driver', function () {
    beforeEach(function () {
        $user = SystemUser::create([
            'username' => 'shopuser', 'home_path' => '/home/shopuser',
            'shell' => '/bin/bash', 'sudo' => false,
        ]);

        $this->app_ = Application::create([
            'system_user_id' => $user->id, 'name' => 'Shop', 'domain' => 'shop.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => '/',
            'status' => 'pending', 'php_version' => '8.4',
        ]);
    });

    it('is resolved for a server running OpenLiteSpeed', function () {
        expect(app(WebServerManager::class)->driver())->toBeInstanceOf(OlsDriver::class);
    });

    it('puts each site in its own directory', function () {
        expect(app(OlsDriver::class)->configPath($this->app_))
            ->toBe('/usr/local/lsws/conf/vhosts/shop.test/vhconf.conf');
    });

    it('writes the vhost before registering it in the shared config', function () {
        $runs = fakeOls(olsConfig());

        app(OlsDriver::class)->apply($this->app_, '/home/shopuser/shop.test');

        $order = collect($runs)->pluck('command')->map(fn ($c) => $c[0] ?? '')->values()->all();
        $tee = array_search('tee', $order, true);
        $cat = array_search('cat', $order, true);

        // A virtualHost block naming a file that does not exist yet fails the
        // config test — and strands the whole server, not just this site.
        expect($order[0])->toBe('mkdir')
            ->and($tee)->toBeLessThan($cat);
    });

    it('unregisters before deleting the files', function () {
        $runs = fakeOls(olsConfig());
        $driver = app(OlsDriver::class);

        $driver->apply($this->app_, '/home/shopuser/shop.test');
        $runs->exchangeArray([]);
        $driver->remove($this->app_);

        $order = collect($runs)->pluck('command')->map(fn ($c) => $c[0] ?? '')->values()->all();

        // The mirror of apply: stop OLS referring to the site before removing
        // what it refers to.
        expect(array_search('cat', $order, true))->toBeLessThan(array_search('rm', $order, true));
    });

    it('renders a vhost pointing at the site, its user and its lsphp', function () {
        fakeOls(olsConfig());

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        expect($config)
            ->toContain('docRoot                   /home/shopuser/shop.test')
            ->toContain('vhDomain                  shop.test')
            // lsphp84, not lsphp8.4 — LiteSpeed drops the dot everywhere.
            ->toContain('extprocessor lsphp84 {')
            ->toContain('path                    '.$this->lsws.'/lsphp84/bin/php')
            // OLS spawns the process itself, so it has to be told who as.
            ->toContain('extUser                 shopuser');
    });

    it('generates rewrites into the vhost rather than relying on .htaccess', function () {
        fakeOls(olsConfig());

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        // OLS needs a full restart after any .htaccess change, which would
        // turn editing WordPress permalinks into server downtime.
        expect($config)
            ->toContain('autoLoadHtaccess        0')
            ->toContain('RewriteRule ^(.*)$ /index.php?$1 [L,QSA]');
    });

    it('serves no PHP from a static site', function () {
        fakeOls(olsConfig());
        $this->app_->update(['serving_profile' => 'static']);

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        // A static site that can execute PHP is one upload away from not
        // being static.
        expect($config)->not->toContain('scripthandler')
            ->and($config)->not->toContain('lsapi');
    });
});

describe('the lsphp stack', function () {
    it('is the stack on an OpenLiteSpeed server', function () {
        expect(app(PhpStackManager::class)->stack())->toBeInstanceOf(LsphpPhpStack::class)
            ->and(app(PhpStack::class)->key())->toBe('lsphp');
    });

    it('detects versions from the lsws tree and expands the compact name', function () {
        File::makeDirectory($this->lsws.'/lsphp83', 0755, true);

        expect(app(LsphpPhpStack::class)->versions())->toBe(['8.4', '8.3'])
            ->and(app(LsphpPhpStack::class)->installed('8.4'))->toBeTrue();
    });

    it('ignores anything in the tree that is not a version', function () {
        File::makeDirectory($this->lsws.'/lsphp', 0755, true);
        File::makeDirectory($this->lsws.'/conf', 0755, true);

        expect(app(LsphpPhpStack::class)->versions())->toBe(['8.4']);
    });

    it('names packages the way LiteSpeed does', function () {
        $stack = app(LsphpPhpStack::class);

        expect($stack->packagePrefix('8.4'))->toBe('lsphp84-')
            ->and($stack->extensionPackage('8.4', 'mysql'))->toBe('lsphp84-mysql')
            // The interpreter package has no suffix at all.
            ->and($stack->versionPackages('8.4')[0])->toBe('lsphp84');
    });

    it('has no per-version service, so PHP adds no rows to Services', function () {
        $stack = app(LsphpPhpStack::class);

        // lshttpd spawns LSPHP; there is nothing to start or stop per version,
        // and a row whose buttons do nothing is worse than no row.
        expect($stack->serviceName('8.4'))->toBeNull()
            ->and($stack->versionForService('lsphp84'))->toBeNull()
            ->and($stack->logPath('8.4'))->toBeNull();
    });

    it('reports no FPM unit on the PHP screen', function () {
        $runs = fakeOls(olsConfig());

        $versions = app(PhpOverview::class)->read()['versions'];

        // Linking the PHP screen to a `php8.4-fpm` that does not exist would
        // send the user to a Services row that is not there.
        expect(collect($versions)->pluck('service')->unique()->all())->toBe([null]);
    });

    it('applies an ini change by restarting the web server', function () {
        $runs = fakeOls(olsConfig());

        app(LsphpPhpStack::class)->reload('8.4');

        expect(collect($runs)->pluck('command'))
            ->toContain(['/usr/local/lsws/bin/lswsctrl', 'restart']);
    });

    it('refuses to toggle extensions rather than pretending to', function () {
        // phpenmod only understands /etc/php; pointed at the lsws tree it
        // exits zero having changed nothing. Saying so beats reporting a
        // success that never happened.
        expect(fn () => app(LsphpPhpStack::class)->extensionToggleCommand('8.4', 'redis', true))
            ->toThrow(PhpConfigException::class);
    });

    it('validates an ini with that version own binary, not with lswsctrl', function () {
        $stack = app(LsphpPhpStack::class);

        // `lswsctrl config_test` checks the web server's config and would pass
        // a php.ini we had just broken.
        expect($stack->configTestCommand('8.4'))->toBe([
            $this->lsws.'/lsphp84/bin/php',
            '-c',
            $this->lsws.'/lsphp84/etc/php/8.4/litespeed/php.ini',
            '-v',
        ]);
    });
});

it('still resolves FPM on an nginx server', function () {
    ServerCapability::query()->update(['web_server' => 'nginx']);

    // The whole point of the driver split: adding OLS must not change what a
    // normal box does.
    expect((new PhpStackManager(app(ServerCapabilities::class)))->stack()->key())->toBe('fpm');
});
