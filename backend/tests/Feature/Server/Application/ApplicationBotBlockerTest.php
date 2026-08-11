<?php

use App\Enums\AiBotPolicy;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * AI Bot Blocker: four plain-language policies, each resolved server-side
 * from `config/ai_bots.php`'s three buckets — training, search and agent.
 *
 * What matters here: neither `block_training` nor `block_agents` ever
 * touches the search/citation bots (that's the whole point of the feature
 * over a blunt on/off toggle), `block_agents` sits between the two by
 * blocking live assistants while keeping the site citable, the vhost swap is
 * reversible the same way Basic Auth's and enable/disable's are, and the
 * catalog endpoint never drifts from what the vhost actually blocks.
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

function botBlockerHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** @param bool $testPasses whether `nginx -t` succeeds. */
function fakeBotBlockerWebServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function botBlockerUrl(): string
{
    return '/api/applications/'.test()->application->id.'/bot-blocker';
}

it('lists the three policies with their resolved bot lists', function () {
    $this->withHeaders(botBlockerHeaders())
        ->getJson('/api/ai-bot-policies')
        ->assertOk()
        ->assertJsonPath('ai_bot_policies.allow_all.blocked_count', 0)
        ->assertJsonPath('ai_bot_policies.allow_all.blocked_bots', []);

    $response = $this->withHeaders(botBlockerHeaders())->getJson('/api/ai-bot-policies');

    $training = $response->json('ai_bot_policies.block_training.blocked_bots');
    $blockAll = $response->json('ai_bot_policies.block_all.blocked_bots');

    expect($training)->toContain('GPTBot', 'ClaudeBot')
        ->and($training)->not->toContain('OAI-SearchBot', 'PerplexityBot')
        ->and($blockAll)->toContain('GPTBot', 'OAI-SearchBot', 'PerplexityBot')
        ->and(count($blockAll))->toBeGreaterThan(count($training));
});

it('sets the policy, re-renders the vhost, and logs the change', function () {
    fakeBotBlockerWebServer();

    $this->withHeaders(botBlockerHeaders())
        ->putJson(botBlockerUrl(), ['policy' => 'block_training'])
        ->assertOk()
        ->assertJsonPath('application.ai_bot_policy', 'block_training');

    expect($this->application->fresh()->ai_bot_policy)->toBe(AiBotPolicy::BlockTraining)
        ->and(ActivityLog::where('type', 'application')->where('action', 'ai_bot_policy_updated')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'nginx' && ($p->command[1] ?? '') === '-t');
});

it('writes the training bot names into the rendered vhost, not the search ones', function () {
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tee') {
            expect($process->input ?? '')->toContain('GPTBot')->not->toContain('OAI-SearchBot');
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(botBlockerHeaders())
        ->putJson(botBlockerUrl(), ['policy' => 'block_training'])
        ->assertOk();
});

it('blocks live AI assistants but keeps the search crawlers under block_agents', function () {
    // The reason this policy exists: an assistant fetch costs a request and
    // returns nothing, while a search crawl is how the site gets cited. One
    // bucket for both made this position unexpressible.
    $bots = AiBotPolicy::BlockAgents->blockedBots();

    expect($bots)->toContain('ChatGPT-User', 'Claude-User', 'Perplexity-User')
        ->and($bots)->toContain('GPTBot', 'ClaudeBot')
        ->and($bots)->not->toContain('OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot');
});

it('keeps the three bot buckets disjoint', function () {
    // A name in two buckets makes "which policy blocks this?" ambiguous and
    // silently widens whichever policy the duplicate leaks into.
    $training = (array) config('ai_bots.training');
    $search = (array) config('ai_bots.search');
    $agent = (array) config('ai_bots.agent');

    expect(array_intersect($training, $search))->toBe([])
        ->and(array_intersect($training, $agent))->toBe([])
        ->and(array_intersect($search, $agent))->toBe([]);

    // And block_all really is all of them, so no bucket can be forgotten.
    expect(AiBotPolicy::BlockAll->blockedBots())
        ->toHaveCount(count($training) + count($search) + count($agent));
});

it('exposes the new policy in the catalog', function () {
    $response = $this->withHeaders(botBlockerHeaders())->getJson('/api/ai-bot-policies');

    $agents = $response->json('ai_bot_policies.block_agents');

    expect($agents['title'])->not->toBeEmpty()
        ->and($agents['description'])->not->toBeEmpty()
        // Strictly between the two neighbours it sits between.
        ->and($agents['blocked_count'])->toBeGreaterThan(count($response->json('ai_bot_policies.block_training.blocked_bots')))
        ->and($agents['blocked_count'])->toBeLessThan(count($response->json('ai_bot_policies.block_all.blocked_bots')));
});

it('restores the previous policy before failing when the config test fails', function () {
    fakeBotBlockerWebServer(testPasses: false);

    $this->withHeaders(botBlockerHeaders())
        ->putJson(botBlockerUrl(), ['policy' => 'block_all'])
        ->assertStatus(500);

    expect($this->application->fresh()->ai_bot_policy)->toBe(AiBotPolicy::AllowAll)
        ->and(ActivityLog::where('action', 'ai_bot_policy_updated')->exists())->toBeFalse();
});

it('is a no-op when the policy is unchanged', function () {
    fakeBotBlockerWebServer();
    $this->application->forceFill(['ai_bot_policy' => 'block_training'])->save();

    $this->withHeaders(botBlockerHeaders())
        ->putJson(botBlockerUrl(), ['policy' => 'block_training'])
        ->assertOk();

    expect(ActivityLog::where('action', 'ai_bot_policy_updated')->exists())->toBeFalse();
});

it('rejects an unknown policy value', function () {
    $this->withHeaders(botBlockerHeaders())
        ->putJson(botBlockerUrl(), ['policy' => 'block_googlebot'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['policy']);
});

it('refuses without manage permission', function () {
    fakeBotBlockerWebServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_bot_blocker', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(botBlockerUrl(), ['policy' => 'block_training'])
        ->assertStatus(403);
});
