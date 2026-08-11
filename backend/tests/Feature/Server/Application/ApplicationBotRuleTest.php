<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Custom bot rules: a site's own additions to, and exemptions from, the
 * built-in AI bot list.
 *
 * The built-in list is curated and carries a review date, which is another
 * way of saying it will be wrong for somebody. Without an exemption the only
 * way to get one crawler back was to switch the whole policy off and unblock
 * all 23; without an addition, the SEO scrapers people actually complain
 * about had nowhere to go.
 *
 * The two things these lock down: the value is refused rather than sanitised
 * (it lands in an elevated-process config file), and a generic value like
 * `bot` — which would also match Googlebot — is refused by name.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Blog',
        'slug' => 'blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

function botRuleHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function botRuleUrl(): string
{
    return '/api/applications/'.test()->application->id.'/bot-blocker';
}

/**
 * Captures whatever was written to the vhost, so a test can assert on the
 * user-agent pattern the site will actually be served with.
 */
function botRuleVhost(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

function fakeBotRuleServer(bool $testPasses = true): void
{
    botRuleVhost()->exchangeArray([]);

    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tee') {
            botRuleVhost()->append((string) ($process->input ?? ''));
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function lastVhost(): string
{
    $written = (array) botRuleVhost();

    return $written === [] ? '' : (string) end($written);
}

it('blocks a custom bot on top of the policy', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'blocked' => ['SemrushBot', 'AhrefsBot']])
        ->assertOk()
        // Order is not part of the contract — the set is.
        ->assertJsonCount(2, 'application.bot_blocked');

    // Both the built-in list and the additions reach the live config.
    expect(lastVhost())->toContain('GPTBot')
        ->and(lastVhost())->toContain('SemrushBot')
        ->and(lastVhost())->toContain('AhrefsBot');
});

it('exempts a built-in bot without switching the whole policy off', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'allowed' => ['Amazonbot']])
        ->assertOk()
        ->assertJsonPath('application.bot_allowed', ['Amazonbot']);

    // The rest of the training list still blocked, that one no longer.
    expect(lastVhost())->toContain('GPTBot')
        ->and(lastVhost())->not->toContain('Amazonbot');
});

it('lets an exemption win over a contradictory custom block', function () {
    fakeBotRuleServer();

    // Two rules that disagree have one safe resolution, and it is the one
    // that keeps traffic flowing.
    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), [
            'policy' => 'allow_all',
            'blocked' => ['SemrushBot'],
            'allowed' => ['SemrushBot'],
        ])
        ->assertOk();

    expect(lastVhost())->not->toContain('SemrushBot');
});

it('keeps the stored rules when only the policy is sent', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'blocked' => ['SemrushBot']])
        ->assertOk();

    // A caller changing just the policy must not silently wipe the list.
    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_all'])
        ->assertOk()
        ->assertJsonPath('application.bot_blocked', ['SemrushBot']);
});

it('clears the list when an empty array is sent', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'blocked' => ['SemrushBot']])
        ->assertOk();

    // Absent means "leave alone", empty means "remove them" — two different
    // intentions that must not collapse into one.
    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'blocked' => []])
        ->assertOk()
        ->assertJsonPath('application.bot_blocked', []);
});

it('does not write the rules when the config test fails', function () {
    fakeBotRuleServer(testPasses: false);

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'block_training', 'blocked' => ['SemrushBot']])
        ->assertStatus(500);

    // The database must look exactly as it did — nothing was reloaded, so
    // the server is still serving the old config and the record has to agree.
    expect($this->application->fresh()->botRules)->toHaveCount(0)
        ->and($this->application->fresh()->ai_bot_policy->value)->toBe('allow_all');
});

it('logs a rule change once, and not at all for an unchanged save', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => ['SemrushBot']])
        ->assertOk();

    expect(ActivityLog::where('action', 'bot_rules_updated')->count())->toBe(1);

    // Saving the same form again changes nothing and should say nothing.
    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => ['SemrushBot']])
        ->assertOk();

    expect(ActivityLog::where('action', 'bot_rules_updated')->count())->toBe(1);
});

it('dedupes case-insensitively, because the vhost match is', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => ['SemrushBot', 'semrushbot', 'SEMRUSHBOT']])
        ->assertOk()
        ->assertJsonPath('application.bot_blocked', ['SemrushBot']);
});

it('refuses a value that would break out of the config file', function (string $value) {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => [$value]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['blocked.0']);

    Process::assertNothingRan();
})->with([
    "GPTBot\nreturn 200",
    'GPT"Bot',
    'GPTBot; }',
    'GPT Bot',
    'GPTBot\\x',
    '$(whoami)',
]);

it('refuses a catch-all that would also block search engines', function (string $value) {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => [$value]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['blocked.0']);
})->with(['bot', 'Bot', 'BOTS', 'crawler', 'spider', 'mozilla', 'Googlebot', 'bingbot']);

it('still accepts the real crawler names people actually type', function (string $value) {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => [$value]])
        ->assertOk();
})->with(['SemrushBot', 'AhrefsBot', 'BLEXBot', 'Google-Extended', 'anthropic-ai', 'SemrushBot-OCOB', 'GPTBot/1.3', 'Applebot-Extended']);

it('refuses more than fifty rules', function () {
    fakeBotRuleServer();

    $this->withHeaders(botRuleHeaders())
        ->putJson(botRuleUrl(), [
            'policy' => 'allow_all',
            'blocked' => array_map(fn (int $i) => 'Bot'.$i, range(1, 51)),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['blocked']);
});

it('refuses a user without manage permission', function () {
    fakeBotRuleServer();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_bot_blocker', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(botRuleUrl(), ['policy' => 'allow_all', 'blocked' => ['SemrushBot']])
        ->assertForbidden();

    expect($this->application->fresh()->botRules)->toHaveCount(0);
});
