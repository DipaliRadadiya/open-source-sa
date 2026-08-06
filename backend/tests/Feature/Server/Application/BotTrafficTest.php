<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Bot traffic: which crawlers actually hit this site, and which of them the
 * current settings would block.
 *
 * The state that matters most here is the one that is easy to get wrong. A
 * log the panel could not read must never be reported as "no bots visited
 * you" — the user would make a real decision on a fact the panel invented.
 * `unavailable`, `empty` and `partial` are three different answers.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

function botTrafficHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function botTrafficUrl(int $days = 7): string
{
    return '/api/applications/'.test()->application->id.'/bot-traffic?days='.$days;
}

/** One combined-format access line, dated relative to now. */
function accessLine(string $agent, int $daysAgo = 0): string
{
    $stamp = now()->subDays($daysAgo)->format('d/M/Y:H:i:s O');

    return '10.0.0.1 - - ['.$stamp.'] "GET / HTTP/1.1" 200 512 "-" "'.$agent.'"';
}

function fakeAccessLog(array $lines, bool $readable = true): void
{
    Process::fake(function ($process) use ($lines, $readable) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tail') {
            return $readable
                ? Process::result(output: implode("\n", $lines))
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory');
        }

        return Process::result(exitCode: 0);
    });
}

it('counts bot hits and says which ones the current settings block', function () {
    $this->application->forceFill(['ai_bot_policy' => 'block_training'])->save();

    fakeAccessLog([
        accessLine('Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot)'),
        accessLine('Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot)', 1),
        accessLine('Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)'),
        accessLine('Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36'),
    ]);

    $response = $this->withHeaders(botTrafficHeaders())->getJson(botTrafficUrl())->assertOk();

    $bots = collect($response->json('bot_traffic.bots'))->keyBy('bot');

    expect($response->json('bot_traffic.status'))->toBe('ok')
        ->and($bots['GPTBot']['hits'])->toBe(2)
        ->and($bots['GPTBot']['category'])->toBe('training')
        // block_training blocks it, and the row says so without the frontend
        // cross-referencing two lists.
        ->and($bots['GPTBot']['blocked'])->toBeTrue()
        ->and($bots['OAI-SearchBot']['hits'])->toBe(1)
        ->and($bots['OAI-SearchBot']['category'])->toBe('search')
        ->and($bots['OAI-SearchBot']['blocked'])->toBeFalse()
        // A human visitor is not a bot and must not appear.
        ->and($bots->has('Mozilla'))->toBeFalse();
});

it('reports unavailable rather than empty when the log cannot be read', function () {
    fakeAccessLog([], readable: false);

    $this->withHeaders(botTrafficHeaders())
        ->getJson(botTrafficUrl())
        ->assertOk()
        // The distinction this whole endpoint hinges on: "I could not look"
        // is not "nothing is there".
        ->assertJsonPath('bot_traffic.status', 'unavailable')
        ->assertJsonPath('bot_traffic.totals.hits', 0);
});

it('reports empty when the log is readable and holds no bot traffic', function () {
    fakeAccessLog([
        accessLine('Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36'),
    ]);

    $this->withHeaders(botTrafficHeaders())
        ->getJson(botTrafficUrl())
        ->assertOk()
        ->assertJsonPath('bot_traffic.status', 'empty')
        ->assertJsonPath('bot_traffic.totals.bots', 0);
});

it('ignores hits older than the requested window', function () {
    fakeAccessLog([
        accessLine('GPTBot/1.3', 2),
        accessLine('GPTBot/1.3', 40),
    ]);

    $response = $this->withHeaders(botTrafficHeaders())->getJson(botTrafficUrl(7))->assertOk();

    expect($response->json('bot_traffic.bots.0.hits'))->toBe(1);
});

it('counts a custom blocked bot and labels it custom', function () {
    fakeAccessLog([accessLine('Mozilla/5.0 (compatible; SemrushBot/7~bl)')]);

    // Not in the shipped list — it is only looked for because this site added
    // it. Leaving custom agents out would mean someone who blocks SemrushBot
    // cannot see on this screen whether the block is working, which is the
    // one question the screen exists to answer.
    $this->application->botRules()->create(['type' => 'block', 'value' => 'SemrushBot']);

    $bots = collect($this->withHeaders(botTrafficHeaders())->getJson(botTrafficUrl())->json('bot_traffic.bots'))->keyBy('bot');

    expect($bots['SemrushBot']['hits'])->toBe(1)
        ->and($bots['SemrushBot']['category'])->toBe('custom')
        ->and($bots['SemrushBot']['blocked'])->toBeTrue();
});

it('reflects an exemption in the blocked column', function () {
    $this->application->forceFill(['ai_bot_policy' => 'block_all'])->save();
    $this->application->botRules()->create(['type' => 'allow', 'value' => 'PerplexityBot']);

    fakeAccessLog([
        accessLine('Mozilla/5.0 (compatible; PerplexityBot/1.0)'),
        accessLine('Mozilla/5.0 (compatible; GPTBot/1.3)'),
    ]);

    $bots = collect($this->withHeaders(botTrafficHeaders())->getJson(botTrafficUrl())->json('bot_traffic.bots'))->keyBy('bot');

    // "Block everything except Perplexity" is the request this feature exists
    // for, and the report has to agree with the vhost about it.
    expect($bots['PerplexityBot']['blocked'])->toBeFalse()
        ->and($bots['GPTBot']['blocked'])->toBeTrue();
});

it('rejects a window outside the supported range', function () {
    $this->withHeaders(botTrafficHeaders())
        ->getJson(botTrafficUrl(400))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['days']);
});

it('refuses a user without log access', function () {
    fakeAccessLog([accessLine('GPTBot/1.3')]);

    $user = User::factory()->create();
    grantPermission($user, 'app_bot_blocker', view: true, manage: true);

    // Holding the bot-blocker permission is not holding the log permission —
    // that is the whole reason this endpoint is gated separately.
    $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
        ->getJson(botTrafficUrl())
        ->assertForbidden();
});
