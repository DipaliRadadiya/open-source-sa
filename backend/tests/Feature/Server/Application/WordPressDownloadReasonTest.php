<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\ServerOpsResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * FS-B3: a WordPress download that never happened — no internet, wordpress.org
 * down, a stalled connection, a bad archive — left the application failed
 * with no reason, so the screen showed "failed at download" plus a log
 * reference and nothing the user could act on.
 *
 * The reason is the one every failed install already records: `failed_reason`
 * on the application and on its run, read by the frontend as
 * `failed_reason_title`. These tests drive the real provisioning job against a
 * faked server and read the answer back through the API, because that is the
 * path the screen takes.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['api.wordpress.org/*' => Http::response(
        "define('AUTH_KEY', 'a1');\ndefine('SECURE_AUTH_KEY', 'a2');\ndefine('LOGGED_IN_KEY', 'a3');\n"
        ."define('NONCE_KEY', 'a4');\ndefine('AUTH_SALT', 'a5');\ndefine('SECURE_AUTH_SALT', 'a6');\n"
        ."define('LOGGED_IN_SALT', 'a7');\ndefine('NONCE_SALT', 'a8');\n"
    )]);
});

function wpDownloadApp(): Application
{
    return Application::create([
        'system_user_id' => test()->su->id,
        'name' => 'Blog '.Str::random(6),
        'domain' => 'blog.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'site_title' => 'My Blog',
            'admin_user' => 'admin',
            'admin_email' => 'me@example.com',
            'admin_password' => 'Sup3rSecretPassw0rd',
            'table_prefix' => 'wp_',
        ],
    ]);
}

/**
 * A server where everything works except what $failing intercepts.
 *
 * @param  Closure(object): mixed  $failing  returns a result for the command it
 *                                           wants to break, null to let it through
 */
function wpDownloadServer(Closure $failing): void
{
    Process::fake(function ($process) use ($failing) {
        $broken = $failing($process);

        if ($broken !== null) {
            return $broken;
        }

        return match (true) {
            // wp-cli already installed, so the only curl is the WordPress download.
            $process->command[0] === 'test' && in_array('-x', $process->command, true) => Process::result(exitCode: 0),
            ($process->command[0] ?? '') === 'mysql' => Process::result(output: '1'),
            default => Process::result(exitCode: 0),
        };
    });
}

function wpDownloadProvision(Application $application): void
{
    (new ProvisionApplication($application->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );
}

function wpDownloadIsCurl(object $process): bool
{
    return $process->command[0] === 'curl'
        && in_array('https://wordpress.org/latest.tar.gz', $process->command, true);
}

it('records why a failed wordpress download failed, and the frontend reads it', function (int $exit, string $stderr, string $reason) {
    wpDownloadServer(fn ($p) => wpDownloadIsCurl($p) ? Process::result(errorOutput: $stderr, exitCode: $exit) : null);
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('download')
        ->and($app->failed_reason)->toBe($reason)
        ->and($app->reference)->not->toBeEmpty();

    $response = $this->getJson("/api/applications/{$app->id}", ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('application.status', 'failed')
        ->assertJsonPath('application.failed_step', 'download')
        ->assertJsonPath('application.failed_reason', $reason)
        ->assertJsonPath('application.failed_reason_title', __("application.failure_reason.{$reason}"));

    // A translated sentence, not the key — and not curl's own output.
    $title = $response->json('application.failed_reason_title');
    expect($title)->not->toBe("application.failure_reason.{$reason}")
        ->and($title)->not->toContain('curl:');
})->with([
    'no internet / DNS' => [6, 'curl: (6) Could not resolve host: wordpress.org', 'download_unreachable'],
    'connection refused' => [7, 'curl: (7) Failed to connect to wordpress.org port 443 after 3 ms: Connection refused', 'download_unreachable'],
    'wordpress.org down (HTTP 503)' => [22, 'curl: (22) The requested URL returned error: 503', 'download_http_error'],
    'file moved (HTTP 404)' => [22, 'curl: (22) The requested URL returned error: 404', 'download_http_error'],
    'curl timed out' => [28, 'curl: (28) Operation timed out after 300001 milliseconds with 0 bytes received', 'download_timeout'],
    'connection dropped mid-download' => [18, 'curl: (18) transfer closed with 1048576 bytes remaining to read', 'download_interrupted'],
    'secure connection refused' => [60, 'curl: (60) SSL certificate problem: certificate has expired', 'download_tls'],
]);

it('records a timeout when the download is killed at its ceiling and nothing was said', function () {
    // The process ceiling equals curl's own --max-time and starts first, so a
    // stalled download is usually killed before curl can say anything: no exit
    // status, no stderr, only that it timed out.
    wpDownloadServer(function ($p) {
        if (wpDownloadIsCurl($p)) {
            throw new ProcessTimedOutException(
                new SymfonyTimedOut(new SymfonyProcess(['curl']), SymfonyTimedOut::TYPE_GENERAL),
                new FakeProcessResult(exitCode: 124),
            );
        }
    });
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('download')
        ->and($app->failed_reason)->toBe('download_timeout');

    $this->getJson("/api/applications/{$app->id}", ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('application.failed_reason', 'download_timeout')
        ->assertJsonPath('application.failed_reason_title', __('application.failure_reason.download_timeout'));
});

it('records a bad archive on the extract step', function (string $stderr) {
    wpDownloadServer(fn ($p) => $p->command[0] === 'tar' ? Process::result(errorOutput: $stderr, exitCode: 2) : null);
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('extract')
        ->and($app->failed_reason)->toBe('archive_invalid');

    $this->getJson("/api/applications/{$app->id}", ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('application.failed_reason', 'archive_invalid')
        ->assertJsonPath('application.failed_reason_title', __('application.failure_reason.archive_invalid'));
})->with([
    'an error page saved as the archive' => ["\ngzip: stdin: not in gzip format\ntar: Child returned status 1\ntar: Error is not recoverable: exiting now\n"],
    'cut short' => ["\ngzip: stdin: unexpected end of file\ntar: Unexpected EOF in archive\ntar: Error is not recoverable: exiting now\n"],
]);

it('records why the wp-cli download failed too, on its own step', function () {
    // The other download in a WordPress install: wp-cli.phar, fetched the
    // first time on a server. Same helper, so the same reasons.
    wpDownloadServer(function ($p) {
        if ($p->command[0] === 'test' && in_array('-x', $p->command, true)) {
            return Process::result(exitCode: 1);
        }

        if ($p->command[0] === 'curl' && str_contains(implode(' ', $p->command), 'wp-cli')) {
            return Process::result(errorOutput: 'curl: (6) Could not resolve host: raw.githubusercontent.com', exitCode: 6);
        }
    });
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('install_cli')
        ->and($app->failed_reason)->toBe('download_unreachable');
});

it('also records the failure on the install run', function () {
    wpDownloadServer(fn ($p) => wpDownloadIsCurl($p) ? Process::result(errorOutput: 'curl: (22) The requested URL returned error: 503', exitCode: 22) : null);
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $run = ApplicationRun::where('application_id', $app->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(ApplicationRun::FAILED)
        ->and($run->failed_step)->toBe('download')
        ->and($run->failed_reason)->toBe('download_http_error')
        ->and($run->reference)->toBe($app->fresh()->reference);
});

it('leaves the success path alone: no failure recorded', function () {
    wpDownloadServer(fn ($p) => null);
    $app = wpDownloadApp();

    wpDownloadProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('active')
        ->and($app->failed_step)->toBeNull()
        ->and($app->failed_reason)->toBeNull();

    $this->getJson("/api/applications/{$app->id}", ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('application.failed_reason', null)
        ->assertJsonPath('application.failed_reason_title', null);
});

it('names nothing it cannot be sure of', function (array $command, int $exit, string $stderr) {
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-unsure',
        result: Process::result(errorOutput: $stderr, exitCode: $exit),
    );

    expect(ProvisioningFailedException::fromResult('download', $result, $command)->reason)->toBeNull();
})->with([
    // 22 means an HTTP error from curl and nothing in particular from anything else.
    'exit 22 from a tool that is not curl' => [['mkdir', '-p', '/tmp/x'], 22, 'mkdir: something'],
    'a curl failure with no known status (write error)' => [['curl', '--fail'], 23, 'curl: (23) Failure writing output to destination'],
    'a disk-full unpack is not a bad archive' => [['tar', '-xf', 'a.tar.gz'], 2, 'tar: wp-config-sample.php: Cannot write: No space left on device'],
    'a permission refusal is not a bad archive' => [['tar', '-xf', 'a.tar.gz'], 2, 'tar: wp-load.php: Cannot open: Permission denied'],
]);

it('reads curl through runuser, the way the installers wrap site tooling', function () {
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-wrapped',
        result: Process::result(errorOutput: 'curl: (22) The requested URL returned error: 404', exitCode: 22),
    );

    expect(ProvisioningFailedException::fromResult(
        'install_cli',
        $result,
        ['runuser', '-u', 'deploy', '--', 'curl', '--fail', 'https://example.com/wp-cli.phar'],
    )->reason)->toBe('download_http_error');
});

it('has every new download reason translated in all eight locales', function () {
    $english = trans('application.failure_reason', [], 'en');

    foreach (['download_unreachable', 'download_timeout', 'download_http_error', 'download_tls', 'download_interrupted', 'archive_invalid'] as $reason) {
        foreach (['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru'] as $locale) {
            $text = trans("application.failure_reason.{$reason}", [], $locale);

            expect($text)->not->toBe("application.failure_reason.{$reason}")
                ->and($text)->not->toBeEmpty();

            if ($locale !== 'en') {
                expect($text)->not->toBe($english[$reason]);
            }

            // An application update shares the classifier and has its own
            // titles; a code missing there reads as the generic "a step failed".
            expect(Lang::has("app_update.reasons.{$reason}", $locale, false))->toBeTrue("app_update.reasons.{$reason} missing in {$locale}");
        }
    }
});
