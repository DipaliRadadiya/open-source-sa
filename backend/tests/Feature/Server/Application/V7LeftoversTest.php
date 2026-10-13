<?php

use App\Contracts\WebServerDriver;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\V7Leftovers;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * v7's own AI-blocker and 7G/8G files for a site, moved aside the first time
 * the owner changes that setting in v8 (Dipali, 2026-10-13). Only files at
 * v7's exact paths for this site, carrying v7's marker for it, never through
 * a link, and put back if the web server's config test then fails.
 */
final class LeftoverHost
{
    /** @var array<string, array{type: string, content?: string}> */
    public static array $paths = [];

    public static bool $testFailsAfterMove = false;

    public static int $moves = 0;

    public static function reset(): void
    {
        self::$paths = [];
        self::$testFailsAfterMove = false;
        self::$moves = 0;

        Process::fake(function ($process) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if ($args[0] === 'stat' && ($args[1] ?? '') === '-c') {
                $path = end($args);
                if (isset(self::$paths[$path])) {
                    return Process::result(output: self::$paths[$path]['type']."\n");
                }
                foreach (array_keys(self::$paths) as $known) {
                    if (str_starts_with($known, rtrim($path, '/').'/')) {
                        return Process::result(output: "directory\n");
                    }
                }

                return Process::result(exitCode: 1, errorOutput: 'No such file');
            }
            if ($args[0] === 'cat' && ($args[1] ?? '') === '--') {
                $entry = self::$paths[end($args)] ?? null;

                return $entry && isset($entry['content']) ? Process::result(output: $entry['content']) : Process::result(exitCode: 1);
            }
            if ($args[0] === 'mv') {
                [$from, $to] = array_slice($args, -2);
                if (isset(self::$paths[$from])) {
                    self::$paths[$to] = self::$paths[$from];
                    unset(self::$paths[$from]);
                    self::$moves++;
                }

                return Process::result();
            }
            if (in_array($args[0], ['nginx', 'apache2ctl', 'apachectl'], true) && in_array('-t', $args, true)) {
                return self::$testFailsAfterMove && self::$moves > 0 ? Process::result(exitCode: 1, errorOutput: 'emerg') : Process::result();
            }

            return Process::result();
        });
    }
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
    $user = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo']);
    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'my-shop', 'slug' => 'my-shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);
    $this->root = $this->application->load('systemUser')->rootPath();
    LeftoverHost::reset();
});

function onWebServer(string $name): void
{
    $driver = Mockery::mock(WebServerDriver::class);
    $driver->shouldReceive('name')->andReturn($name);
    $manager = Mockery::mock(WebServerManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    app()->instance(WebServerManager::class, $manager);
}

function v7File(string $path, string $content, string $type = 'regular file'): void
{
    LeftoverHost::$paths[$path] = ['type' => $type, 'content' => $content];
}

// v7's own templates, with this site's safe name (my-shop → my_shop).
const V7_AI_MAP = "map \$http_user_agent \$my_shop_block_ai { # Dont Change anything into this line\n    ~*(?i)(gptbot) 1;\n    default 0;\n}";
const V7_AI_SITE = "if (\$http_user_agent ~* \"YandexBot\") {\n    set \$my_shop_block_ai 0;\n}\nif (\$my_shop_block_ai) {\n    return 403;\n}";

it('moves this site\'s v7 AI-blocker files on nginx, and only those', function () {
    onWebServer('nginx');
    v7File('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', V7_AI_MAP);
    v7File("{$this->root}/conf/nginx/ai-bot-blocker.conf", V7_AI_SITE);
    v7File('/etc/nginx/conf.d/other-ai-bot-blocker.conf', str_replace('my_shop', 'other', V7_AI_MAP));

    $moved = app(V7Leftovers::class)->remove($this->application, 'ai_bot_blocker');

    expect(array_column($moved, 'from'))->toBe(['/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', "{$this->root}/conf/nginx/ai-bot-blocker.conf"])
        ->and(LeftoverHost::$paths)->toHaveKey('/etc/nginx/conf.d/other-ai-bot-blocker.conf')
        ->and(collect($moved)->every(fn ($m) => str_starts_with($m['to'], '/var/lib/panel/v7-leftovers/my-shop/')))->toBeTrue();
});

it('leaves a file of the same name that is not v7\'s for this site', function () {
    onWebServer('nginx');
    v7File('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', "# my own map\nmap \$x \$y { default 0; }");
    v7File("{$this->root}/conf/nginx/ai-bot-blocker.conf", str_replace('my_shop', 'someone_else', V7_AI_SITE));

    expect(app(V7Leftovers::class)->remove($this->application, 'ai_bot_blocker'))->toBe([])
        ->and(LeftoverHost::$moves)->toBe(0);
});

it('never follows a link, to the file or its folder', function (string $kind) {
    onWebServer('nginx');
    if ($kind === 'file') {
        v7File("{$this->root}/conf/nginx/ai-bot-blocker.conf", V7_AI_SITE, 'symbolic link');
    } else {
        v7File("{$this->root}/conf/nginx/ai-bot-blocker.conf", V7_AI_SITE);
        LeftoverHost::$paths["{$this->root}/conf/nginx"] = ['type' => 'symbolic link'];
    }

    expect(app(V7Leftovers::class)->remove($this->application, 'ai_bot_blocker'))->toBe([]);
})->with(['file', 'folder']);

it('knows v7\'s paths on Apache and OpenLiteSpeed', function (string $server, string $path) {
    onWebServer($server);
    v7File(str_replace('{root}', $this->root, $path), "RewriteCond %{HTTP_USER_AGENT} YandexBot [NC]\nRewriteRule ^ - [L]\nRewriteCond %{HTTP_USER_AGENT} (GPTBot) [NC]\nRewriteRule (.*) - [F,L]");

    expect(array_column(app(V7Leftovers::class)->remove($this->application, 'ai_bot_blocker'), 'from'))->toBe([str_replace('{root}', $this->root, $path)]);
})->with([
    ['apache', '{root}/conf/apache2/ai-bot-blocker.conf'],
    ['openlitespeed', '{root}/conf/openlitespeed/rewrites/ai-bot-blocker.conf'],
]);

it('moves this site\'s v7 7G/8G files on nginx: conf.d, snippet and site include', function () {
    onWebServer('nginx');
    v7File('/etc/nginx/conf.d/8g-firewall-my_shop.conf', "map \$query_string \$bad_querystring_ng_my_shop {\n default 0;\n}");
    v7File('/etc/nginx/snippets/8g-my_shop.conf', "if (\$bad_cookie_ng_my_shop) {\n    return 403;\n}");
    v7File("{$this->root}/conf/nginx/8g-firewall.conf", "# Dont change anything into this line.\ninclude /etc/nginx/snippets/8g-my_shop.conf;");
    v7File('/etc/nginx/conf.d/7g-firewall-my_shop.conf', "map \$query_string \$bad_querystring_7g_my_shop {\n default 0;\n}");

    $moved = array_column(app(V7Leftovers::class)->remove($this->application, 'firewall'), 'from');

    expect($moved)->toEqualCanonicalizing([
        '/etc/nginx/conf.d/8g-firewall-my_shop.conf', '/etc/nginx/snippets/8g-my_shop.conf',
        "{$this->root}/conf/nginx/8g-firewall.conf", '/etc/nginx/conf.d/7g-firewall-my_shop.conf',
    ]);
});

it('knows v7\'s firewall files on Apache and OpenLiteSpeed by their 7G/8G header', function (string $server, string $path) {
    onWebServer($server);
    v7File(str_replace('{root}', $this->root, $path), "# 8G FIREWALL v1.4 20250120\n# https://perishablepress.com/8g-firewall/\n");

    expect(array_column(app(V7Leftovers::class)->remove($this->application, 'firewall'), 'from'))->toBe([str_replace('{root}', $this->root, $path)]);
})->with([
    ['apache', '{root}/conf/apache/8g-firewall.conf'],
    ['openlitespeed', '{root}/conf/openlitespeed/rewrites/8g-firewall.conf'],
]);

it('removes them when the AI bot blocker is changed, and logs it', function () {
    v7File('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', V7_AI_MAP);
    v7File("{$this->root}/conf/nginx/ai-bot-blocker.conf", V7_AI_SITE);

    $this->actingAs($this->admin)->putJson("/api/applications/{$this->application->id}/bot-blocker", ['policy' => 'block_all'])->assertOk();

    expect(LeftoverHost::$paths)->not->toHaveKey('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf')
        ->and(LeftoverHost::$moves)->toBe(2)
        ->and(ActivityLog::where('action', 'v7_leftovers_removed')->exists())->toBeTrue();
});

it('puts them back when the web server rejects the config without them', function () {
    v7File('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', V7_AI_MAP);
    LeftoverHost::$testFailsAfterMove = true;

    $this->actingAs($this->admin)->putJson("/api/applications/{$this->application->id}/bot-blocker", ['policy' => 'block_all'])->assertOk();

    expect(LeftoverHost::$paths)->toHaveKey('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf')
        ->and(ActivityLog::where('action', 'v7_leftovers_removed')->exists())->toBeFalse();
});

it('touches nothing when the setting was not changed', function () {
    v7File('/etc/nginx/conf.d/my-shop-ai-bot-blocker.conf', V7_AI_MAP);

    $this->actingAs($this->admin)->putJson("/api/applications/{$this->application->id}/bot-blocker", ['policy' => 'allow_all'])->assertOk();

    expect(LeftoverHost::$moves)->toBe(0);
});

it('removes the v7 firewall files when the Web Firewall is saved', function () {
    v7File('/etc/nginx/conf.d/8g-firewall-my_shop.conf', "map \$query_string \$bad_querystring_ng_my_shop {\n default 0;\n}");

    $this->actingAs($this->admin)->putJson("/api/applications/{$this->application->id}/waf", ['enabled' => true, 'mode' => 'detect'])->assertOk();

    expect(LeftoverHost::$paths)->not->toHaveKey('/etc/nginx/conf.d/8g-firewall-my_shop.conf');
});
