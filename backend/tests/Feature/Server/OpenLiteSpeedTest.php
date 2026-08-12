<?php

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Exceptions\Server\WebServer\OlsListenerNotFoundException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\PhpExtensionManager;
use App\Services\Server\Php\PhpOverview;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\OlsSharedConfig;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        // Node too, so the catalog assertions below are about the web server
        // rather than about a runtime this fixture happens to lack.
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->lsws = sys_get_temp_dir().'/sv-oss-lsws-'.getmypid();
    File::deleteDirectory($this->lsws);
    File::makeDirectory($this->lsws.'/lsphp84/etc/php/8.4/litespeed', 0755, true);
    File::makeDirectory($this->lsws.'/lsphp84/bin', 0755, true);
    // Both binaries, because the panel probes for them rather than assuming.
    File::put($this->lsws.'/lsphp84/bin/php', '');
    File::put($this->lsws.'/lsphp84/bin/lsphp', '');

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

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test', 'www.shop.test'], '/home/shopuser/shop.test');

        expect(sharedConfig())
            ->toContain('virtualHost shop.test {')
            ->toContain('map                     shop.test shop.test, www.shop.test');
    });

    it('never touches anything outside its markers', function () {
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

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

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        $config = sharedConfig();
        $listener = strpos($config, 'listener Default {');
        $map = strpos($config, 'map                     shop.test');
        $close = strpos($config, "\n}", $listener);

        expect($map)->toBeGreaterThan($listener)->toBeLessThan($close);
    });

    it('is idempotent — registering twice leaves one block, not two', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');
        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        // The region is rebuilt rather than appended to, so a re-provision
        // cannot duplicate a site.
        expect(substr_count(sharedConfig(), 'virtualHost shop.test {'))->toBe(1);
    });

    it('points vhRoot at the site, not at the config directory', function () {
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        // With `restrained 1` the vhost may only reach files under vhRoot. Set
        // to the config directory — as it was — the document root sits outside
        // the only tree the site is allowed to read, and every request is
        // refused. The site would never have served a single page.
        expect(sharedConfig())
            ->toContain('vhRoot                  /home/shopuser/shop.test/')
            ->toContain('configFile              /usr/local/lsws/conf/vhosts/shop.test/vhconf.conf');
    });

    it('preserves another site vhRoot when rebuilding the region', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('blog.test', ['blog.test'], '/home/bloguser/blog.test');
        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        // The region is rebuilt on every change, so each site's root has to be
        // read back out rather than regenerated from a default — otherwise
        // adding one site silently moves another.
        expect(sharedConfig())->toContain('vhRoot                  /home/bloguser/blog.test/');
    });

    it('keeps other managed sites when one is removed', function () {
        fakeOls(olsConfig());
        $shared = app(OlsSharedConfig::class);

        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');
        $shared->register('blog.test', ['blog.test'], '/home/bloguser/blog.test');
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

        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');
        $shared->unregister('shop.test');

        // A leftover map pointing at a gone virtualHost fails config_test and
        // blocks every later reload — for every site, not just this one.
        expect(sharedConfig())->not->toContain('shop.test');
    });

    it('restores the whole file when the config test fails', function () {
        $runs = fakeOls(olsConfig(), testPasses: false);

        $result = app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

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

        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');
        $runs->exchangeArray([]);
        $shared->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        // No reason to rewrite a file every site depends on to say the same
        // thing again.
        expect(collect($runs)->pluck('command')->map(fn ($c) => $c[0] ?? ''))
            ->not->toContain('tee');
    });

    it('keeps regex metacharacters in paths intact', function () {
        // `$1` and `\1` are backreferences in a replacement string. vhost_root
        // is operator-set config, so it can contain them, and the old
        // preg_replace ate them silently — in the file every site depends on.
        config(['server.web_server_drivers.openlitespeed.vhost_root' => '/srv/$1/vhosts']);
        fakeOls(olsConfig());

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        expect(sharedConfig())->toContain('/srv/$1/vhosts/shop.test/vhconf.conf');
    });

    it('restores the file when the write itself fails, not just the test', function () {
        $runs = fakeOls(olsConfig());
        $original = sharedConfig();

        // tee truncates before writing, so a failed write leaves the file
        // empty — with the backup sitting right there unused.
        Process::fake(function ($process) use ($runs, $original) {
            $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

            return match ($process->command[0] ?? '') {
                'cat' => Process::result(output: $original),
                'tee' => Process::result(output: '', errorOutput: 'No space left on device', exitCode: 1),
                default => Process::result(output: ''),
            };
        });

        $result = app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        expect($result->failed())->toBeTrue()
            ->and(collect($runs)->pluck('command'))
            ->toContain(['cp', '-f', sharedPath().'.panel-bak', sharedPath()]);
    });

    it('finds the listener closing brace by counting, not by column', function () {
        // A hand-written config that indents its closing brace would otherwise
        // send the search into the next block, where `map` is illegal.
        fakeOls("listener Default {\n  address *:80\n  }\n\nvirtualHost legacy {\n  vhRoot /x/\n}\n");

        app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test');

        $config = sharedConfig();
        $map = strpos($config, 'map                     shop.test');

        expect($map)->toBeLessThan(strpos($config, 'virtualHost legacy'));
    });

    it('refuses when there is no listener to map into', function () {
        fakeOls("serverName Nothing\nuser nobody\n");

        // Inventing a listener means guessing an address and port — either a
        // no-op or a hijack of one something else owns.
        expect(fn () => app(OlsSharedConfig::class)->register('shop.test', ['shop.test'], '/home/shopuser/shop.test'))
            ->toThrow(OlsListenerNotFoundException::class);
    });
});

describe('the driver', function () {
    beforeEach(function () {
        $user = SystemUser::create([
            'username' => 'shopuser', 'home_path' => '/home/shopuser',
            'shell' => '/bin/bash', 'sudo' => false,
        ]);

        $this->app_ = Application::forceCreate([
            'system_user_id' => $user->id, 'name' => 'Shop',
            'slug' => 'shop', 'domain' => 'shop.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => '/',
            'status' => 'pending', 'php_version' => '8.4',
        ]);
    });

    it('is resolved for a server running OpenLiteSpeed', function () {
        expect(app(WebServerManager::class)->driver())->toBeInstanceOf(OlsDriver::class);
    });

    it('puts each site in its own directory', function () {
        expect(app(OlsDriver::class)->configPath($this->app_))
            ->toBe('/usr/local/lsws/conf/vhosts/shop/vhconf.conf');
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

    it('does not delete the vhost files when the shared entry did not go', function () {
        // The markers are comments, and the OLS WebAdmin console strips
        // comments when it rewrites the file. The entries then survive outside
        // the region, `unregister()` changes nothing and reports success, and
        // deleting the file it still points at breaks config_test for every
        // site on the box.
        fakeOls("listener Default {\n  address *:80\n  map shop.test shop.test\n}\n\nvirtualHost shop.test {\n  vhRoot /x/\n}\n");

        app(OlsDriver::class)->remove($this->app_);

        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'rm');
    });

    it('refuses to rm -rf anything that is not inside the vhost root', function () {
        fakeOls(olsConfig());
        // Both, not just the domain: the config is named after the slug and
        // only falls back to the domain, so blanking one still leaves a name.
        $this->app_->forceFill(['slug' => null, 'domain' => ''])->save();

        // With neither, the target is the vhost root itself — deleting every
        // site's configuration on the server. Validation upstream stops this
        // today; the guard means it stays stopped.
        expect(fn () => app(OlsDriver::class)->remove($this->app_))
            ->toThrow(HttpException::class);

        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'rm');
    });

    it('renders a vhost pointing at the site, its user and its lsphp', function () {
        fakeOls(olsConfig());

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        expect($config)
            ->toContain('docRoot                   /home/shopuser/shop.test')
            ->toContain('vhDomain                  shop.test')
            // lsphp84, not lsphp8.4 — LiteSpeed drops the dot everywhere.
            ->toContain('extprocessor lsphp84 {')
            // bin/lsphp, not bin/php: the CLI does not speak LSAPI, so a
            // vhost pointed at it would run no PHP at all.
            ->toContain('path                    '.$this->lsws.'/lsphp84/bin/lsphp')
            // OLS spawns the process itself, so it has to be told who as.
            ->toContain('extUser                 shopuser');
    });

    it('uses OpenLiteSpeed regex context syntax, not nginx', function () {
        fakeOls(olsConfig());

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        // `context ~ /re/` is nginx. OLS spells it `exp:`, and the wrong one
        // fails the config test — which would have stopped every OLS site from
        // provisioning, not just broken this rule.
        expect($config)->toContain('context exp:^/\.(git|svn|hg|bzr|env) {')
            ->and($config)->not->toContain('context ~')
            // .well-known must stay reachable or certificates cannot be issued.
            // The deny rule names the directories it blocks rather than using a
            // lookahead, so it must not name this one — and the challenge path
            // is served explicitly, since node and static sites have no
            // document root for certbot to drop a token into.
            ->and($config)->not->toContain('bzr|env|well-known')
            ->and($config)->toContain('context /.well-known/acme-challenge {');
    });

    it('creates the log directory the vhost names', function () {
        $runs = fakeOls(olsConfig());

        app(OlsDriver::class)->apply($this->app_, '/home/shopuser/shop/public_html');

        // OLS does not create it, and silently falls back to the server-wide
        // log — so a site's errors land where nobody thinks to look.
        //
        // Under the site's own root (slug), not its domain: the vhost root is
        // also what `restrained 1` confines the vhost to, so a domain-named
        // one put the document root outside the restraint — and moved a live
        // site's logs every time someone changed its domain.
        expect(collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'mkdir'))
            ->toContain('/home/shopuser/shop/logs');
    });

    it('generates rewrites into the vhost rather than relying on .htaccess', function () {
        fakeOls(olsConfig());

        $config = app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test');

        // OLS needs a full restart after any .htaccess change, which would
        // turn editing permalinks into server downtime — so everything that
        // can live in the vhost does.
        expect($config)
            // Apache's mod_rewrite, which OLS implements — not a translation
            // of nginx's try_files. The loop guard keeps its leading slash
            // because OLS does not strip one at vhost level.
            ->toContain('RewriteRule ^/index\\.php$ - [L]')
            ->toContain('RewriteRule . /index.php [L]')
            ->not->toContain('QSA');
    });

    it('reads .htaccess for WordPress, because LSCache has no other way in', function () {
        fakeOls(olsConfig());

        // The plugin writes its cache rules to .htaccess and OLS's cache
        // module reads them from there. With autoload off, LSCache installs,
        // activates, reports itself enabled — and caches nothing. That is the
        // reason people choose OLS, so it cannot be silently off.
        expect(app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test'))
            ->toContain('autoLoadHtaccess        1');
    });

    it('does not read .htaccess for anything else', function () {
        fakeOls(olsConfig());
        $this->app_->update(['site_type' => 'php']);

        // A blank PHP site gets its whole rewrite from the vhost, so an
        // .htaccess someone drops in should not start costing restarts.
        expect(app(OlsDriver::class)->renderConfig($this->app_, '/home/shopuser/shop.test'))
            ->toContain('autoLoadHtaccess        0');
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

    it('installs an extension without trying to enable it afterwards', function () {
        Process::fake(fn ($process) => Process::result(output: ''));

        // apt drops the ini where LSPHP already reads it. Calling enable()
        // here would refuse and report `enable_failed` for an install that
        // actually worked — which is what this did before.
        app(PhpExtensionManager::class)->install('8.4', 'redis');

        Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'apt-get'
            && in_array('lsphp84-redis', $p->command, true));
        Process::assertNotRan(fn ($p) => str_contains((string) ($p->command[0] ?? ''), 'phpenmod'));
    });

    it('refuses to toggle extensions rather than pretending to', function () {
        // phpenmod only understands /etc/php; pointed at the lsws tree it
        // exits zero having changed nothing. Saying so beats reporting a
        // success that never happened.
        expect(fn () => app(LsphpPhpStack::class)->extensionToggleCommand('8.4', 'redis', true))
            ->toThrow(PhpConfigException::class);
    });

    it('finds the binaries in the lsws tree when they are there', function () {
        $stack = app(LsphpPhpStack::class);

        expect($stack->binaryPath('8.4'))->toBe($this->lsws.'/lsphp84/bin/php')
            // The LSAPI build for the vhost, the CLI for installers. Not
            // interchangeable.
            ->and($stack->handlerPath('8.4'))->toBe($this->lsws.'/lsphp84/bin/lsphp');
    });

    it('falls through to the other places LSPHP gets installed', function () {
        // Some builds put it outside the lsws tree — and note the dot, where
        // the lsws tree uses `lsphp84`. Taken from the Go agent that ran on
        // production servers; a single hardcoded pattern is right on the
        // common install and quietly wrong on the rest.
        File::delete($this->lsws.'/lsphp84/bin/php');

        $elsewhere = $this->lsws.'/usr-bin';
        File::makeDirectory($elsewhere, 0755, true);
        File::put($elsewhere.'/lsphp8.4', '');

        config(['server.php_stacks.lsphp.binary_candidates' => [
            '{root}/lsphp{compact}/bin/php',
            $elsewhere.'/lsphp{version}',
        ]]);

        expect(app(LsphpPhpStack::class)->binaryPath('8.4'))->toBe($elsewhere.'/lsphp8.4');
    });

    it('names the path it expected when nothing is found', function () {
        File::deleteDirectory($this->lsws);

        // An absolute path that is missing tells the operator which file we
        // looked for. A bare `lsphp8.4` on PATH would fail as "command not
        // found", which reads like a broken panel rather than a missing
        // package.
        expect(app(LsphpPhpStack::class)->binaryPath('8.4'))
            ->toBe($this->lsws.'/lsphp84/bin/php');
    });

    it('expands compact package names into real versions', function () {
        // Left as `84`, it never matches the `8.4` that versions() reports, so
        // an installed version stays listed as installable forever — and the
        // value posted back fails the major.minor rule, so it can never be
        // installed either.
        expect(app(LsphpPhpStack::class)->installableVersions("lsphp84 - LiteSpeed PHP\nlsphp83 - LiteSpeed PHP\n"))
            ->toBe(['8.4', '8.3']);
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

describe('the site type catalog', function () {
    it('offers every site type, because nothing in them depends on the web server', function () {
        $catalog = collect(app(SiteTypeManager::class)->catalog());

        // An audit found no installer or site type that mentions .htaccess,
        // mod_rewrite or Apache, none declaring extension requirements, and no
        // web-server concept in the SiteType contract. A shorter list here
        // would be a guess about risk dressed as a capability limit.
        // NodeBB is the one exception, and not because of the web server:
        // it takes MongoDB alone, and this fixture has no database engine.
        expect($catalog->where('available', false)->pluck('name')->all())->toBe(['nodebb'])
            ->and($catalog->firstWhere('name', 'nodebb')['unavailable_reason'])
            ->toBe('This application needs MongoDB, which this server does not have.')
            ->and($catalog)->toHaveCount(17);
    });

    it('can still restrict a web server that genuinely needs it', function () {
        config(['server.web_server_drivers.openlitespeed.site_types' => ['wordpress', 'static']]);

        $catalog = collect(app(SiteTypeManager::class)->catalog());
        $joomla = $catalog->firstWhere('name', 'joomla');

        expect($catalog->where('available', true)->pluck('name')->sort()->values()->all())
            ->toBe(['static', 'wordpress'])
            // Greyed with a reason, not hidden — hiding reads as a missing
            // feature rather than a deliberate limit.
            ->and($joomla['unavailable_reason'])->toBe('This application is not available on OpenLiteSpeed servers yet.')
            // Nothing installable fixes a web-server block, so the card must
            // not offer an action that cannot work.
            ->and($joomla['installable_runtime'])->toBeNull();
    });

    it('refuses to create a type the web server does not offer', function () {
        config(['server.web_server_drivers.openlitespeed.site_types' => ['wordpress']]);

        $this->seed(PermissionSeeder::class);
        $user = User::factory()->admin()->create();
        $token = $user->createToken('t')->plainTextToken;

        SystemUser::create([
            'username' => 'u1', 'home_path' => '/home/u1',
            'shell' => '/bin/bash', 'sudo' => false,
        ]);

        // The grid and the endpoint read the same method, so a card the user
        // could click can never be refused for a reason the grid did not show.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/applications', [
                'system_user_id' => SystemUser::query()->value('id'),
                'name' => 'Shop', 'domain' => 'j.test', 'site_type' => 'joomla',
            ])
            ->assertJsonValidationErrors('site_type');
    });

    it('does not restrict nginx, which serves everything we ship', function () {
        ServerCapability::query()->update(['web_server' => 'nginx']);

        $catalog = collect(app(SiteTypeManager::class)->catalog());

        // No `site_types` list on a driver means no restriction — the OLS
        // limit must not leak into the servers that were already fine. NodeBB
        // is blocked by its database, not by nginx.
        expect($catalog->where('available', false)->pluck('name')->all())->toBe(['nodebb']);
    });
});

it('still resolves FPM on an nginx server', function () {
    ServerCapability::query()->update(['web_server' => 'nginx']);

    // The whole point of the driver split: adding OLS must not change what a
    // normal box does.
    expect((new PhpStackManager(app(ServerCapabilities::class)))->stack()->key())->toBe('fpm');
});
