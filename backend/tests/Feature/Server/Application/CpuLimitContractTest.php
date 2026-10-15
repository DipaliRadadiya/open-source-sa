<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\DockerDatabase;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->systemUser = SystemUser::create(['username' => 'cpuowner', 'home_path' => '/home/cpuowner']);
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
    $this->mock(HostCpus::class)->shouldReceive('count')->andReturn(4);
    Queue::fake();
    Process::fake(function ($process) {
        $args = $process->command;
        if (($args[0] ?? '') === 'sudo') {
            $args = array_slice($args, 2);
        }

        if (in_array('config', $args, true) && in_array('json', $args, true)) {
            return Process::result(output: json_encode(['services' => ['web' => [
                'image' => 'nginx:alpine',
                'ports' => [['host_ip' => '127.0.0.1', 'published' => '20001', 'target' => 80]],
            ]]]));
        }

        return match ($args[0] ?? '') {
            'cat' => Process::result(exitCode: 1),
            'getent' => Process::result(exitCode: 2),
            default => Process::result(),
        };
    });
});

function rcF02CpuPayload(string $mode, array $input): array
{
    $payload = $input + [
        'name' => 'CPU Contract', 'domain' => 'cpu.example.test',
        'system_user_id' => test()->systemUser->id,
        'site_type' => $mode === 'recipe' ? 'vaultwarden' : 'docker',
    ];

    return $payload + match ($mode) {
        'image' => ['image' => 'nginx:alpine', 'container_port' => 80],
        'compose' => ['compose' => "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - '127.0.0.1:20001:80'\n"],
        default => [],
    };
}

function rcF02CpuSite(string $mode): Application
{
    return Application::forceCreate(rcF02CpuPayload($mode, []) + [
        'slug' => 'cpu-contract', 'serving_profile' => 'docker', 'status' => 'pending',
        'web_root' => 'public_html', 'app_port' => 20001, 'cpu_limit' => '2',
    ])->fresh();
}

dataset('rc-f02 deployment modes', ['recipe', 'image', 'compose']);

dataset('rc-f02 raw cpu line breaks', [
    'terminal LF' => ["1\n"],
    'terminal CR' => ["1\r"],
    'terminal CRLF' => ["1\r\n"],
    'leading LF' => ["\n1"],
    'embedded numeric LF' => ["1\n1"],
    'LF only' => ["\n"],
    'CR only' => ["\r"],
    'CRLF only' => ["\r\n"],
    'whitespace and CRLF' => [" \t\r\n "],
    'privileged directive' => ["1\nprivileged: true"],
    'host network directive' => ["1\r\nnetwork_mode: host"],
    'socket mount directive' => ["1\nvolumes: [/var/run/docker.sock:/var/run/docker.sock]"],
]);

dataset('rc-f02 valid cpu inputs', [
    'omitted' => [[], null],
    'null' => [['cpu_limit' => null], null],
    'empty' => [['cpu_limit' => ''], null],
    'horizontal whitespace only' => [['cpu_limit' => " \t "], null],
    'integer string' => [['cpu_limit' => '1'], '1'],
    'decimal' => [['cpu_limit' => '1.5'], '1.5'],
    'two decimal places' => [['cpu_limit' => '1.25'], '1.25'],
    'floor' => [['cpu_limit' => '0.01'], '0.01'],
    'host maximum' => [['cpu_limit' => '4'], '4'],
    'padded decimal' => [['cpu_limit' => " \t1.5\t "], '1.5'],
]);

dataset('rc-f02 invalid cpu inputs', [
    'zero' => ['0'], 'negative' => ['-1'], 'below floor' => ['0.001'],
    'precision' => ['1.555'], 'over host' => ['4.01'], 'exponent' => ['1e0'],
    'not decimal' => ['one'], 'embedded space' => ['1 2'], 'not string' => [1],
]);

it('rejects raw CPU line breaks on actual POST without creating any application', function (string $mode, string $cpu) {
    $this->actingAs($this->admin)->postJson('/api/applications', rcF02CpuPayload($mode, ['cpu_limit' => $cpu]))
        ->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');

    expect(Application::count())->toBe(0)
        ->and(ApplicationDomain::count())->toBe(0)
        ->and(SystemUser::count())->toBe(1);
    Queue::assertNothingPushed();
})->with('rc-f02 deployment modes')->with('rc-f02 raw cpu line breaks');

it('rejects raw CPU line breaks on actual PUT without changing the row or stored compose', function (string $mode, string $cpu) {
    $site = rcF02CpuSite($mode);
    $before = $site->getRawOriginal();
    $this->actingAs($this->admin)->putJson('/api/applications/'.$site->id.'/container', ['cpu_limit' => $cpu])
        ->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');

    expect($site->fresh()->getRawOriginal())->toBe($before)
        ->and(ActivityLog::where('action', 'container_updated')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with('rc-f02 deployment modes')->with('rc-f02 raw cpu line breaks');

it('retains normal CPU defaults decimals and horizontal whitespace on actual POST', function (string $mode, array $input, ?string $expected) {
    $response = $this->actingAs($this->admin)->postJson('/api/applications', rcF02CpuPayload($mode, $input))
        ->assertCreated()->assertJsonPath('application.cpu_limit', $expected);
    $site = Application::findOrFail($response->json('application.id'));

    expect($site->cpu_limit)->toBe($expected)->and($site->site_type)->toBe($mode === 'recipe' ? 'vaultwarden' : 'docker');
})->with('rc-f02 deployment modes')->with('rc-f02 valid cpu inputs');

it('retains normal CPU inputs on actual PUT and leaves an omitted limit unchanged', function (string $mode, array $input, ?string $expected) {
    $site = rcF02CpuSite($mode);
    $expected = $input === [] ? '2' : $expected;
    $this->actingAs($this->admin)->putJson('/api/applications/'.$site->id.'/container', $input)
        ->assertOk()->assertJsonPath('application.cpu_limit', $expected);

    expect($site->fresh()->cpu_limit)->toBe($expected)
        ->and($site->fresh()->compose)->toBe($site->compose);
})->with('rc-f02 deployment modes')->with('rc-f02 valid cpu inputs');

it('retains decimal type precision and host bounds on actual POST', function (string $mode, mixed $cpu) {
    $this->actingAs($this->admin)->postJson('/api/applications', rcF02CpuPayload($mode, ['cpu_limit' => $cpu]))
        ->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');
    expect(Application::count())->toBe(0);
})->with('rc-f02 deployment modes')->with('rc-f02 invalid cpu inputs');

it('retains decimal type precision and host bounds on actual PUT', function (string $mode, mixed $cpu) {
    $site = rcF02CpuSite($mode);
    $before = $site->getRawOriginal();
    $this->actingAs($this->admin)->putJson('/api/applications/'.$site->id.'/container', ['cpu_limit' => $cpu])
        ->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');
    expect($site->fresh()->getRawOriginal())->toBe($before);
})->with('rc-f02 deployment modes')->with('rc-f02 invalid cpu inputs');

it('retains creation and container update authorization', function (bool $authenticated, int $status) {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    $this->postJson('/api/applications', rcF02CpuPayload('recipe', ['cpu_limit' => "1\n"]))->assertStatus($status);
    expect(Application::count())->toBe(0);
    $site = rcF02CpuSite('recipe');
    $before = $site->getRawOriginal();
    $this->putJson('/api/applications/'.$site->id.'/container', ['cpu_limit' => "1\n"])->assertStatus($status);
    expect($site->fresh()->getRawOriginal())->toBe($before);
})->with([[false, 401], [true, 403]]);

it('does not introduce PATCH support for the PUT-only container settings route', function (string $mode) {
    $site = rcF02CpuSite($mode);
    $before = $site->getRawOriginal();
    $this->actingAs($this->admin)->patchJson('/api/applications/'.$site->id.'/container', ['cpu_limit' => "1\n"])
        ->assertStatus(405);
    expect($site->fresh()->getRawOriginal())->toBe($before);
})->with('rc-f02 deployment modes');

it('keeps unrelated trimming and file password exceptions while retaining raw CPU line breaks', function () {
    Route::post('/api/rc-f02-normalization', fn (Request $request) => response()->json($request->all()));
    $file = "  indented\n";
    $this->postJson('/api/rc-f02-normalization', [
        'cpu_limit' => "1\n", 'ordinary' => "  ordinary\n", 'empty' => ' ',
        'content' => $file, 'contents' => $file, 'password' => " secret\n",
    ])->assertOk()->assertExactJson([
        'cpu_limit' => "1\n", 'ordinary' => 'ordinary', 'empty' => null,
        'content' => $file, 'contents' => $file, 'password' => " secret\n",
    ]);
});

it('also rejects raw CPU line breaks before Docker database creation uses the shared field', function (string $cpu) {
    $this->actingAs($this->admin)->postJson('/api/docker/databases', [
        'name' => 'cpudb', 'engine' => 'postgres', 'version' => '17', 'cpu_limit' => $cpu,
    ])->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');
    expect(DockerDatabase::count())->toBe(0);
})->with('rc-f02 raw cpu line breaks');
