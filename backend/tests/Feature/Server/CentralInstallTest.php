<?php

use App\Console\Commands\PanelCreateAdmin;
use App\Models\User;
use App\Services\AdministratorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/*
 * A server Central builds (2026-10-17): the first administrator created during
 * the install, progress reported to Central, a details file left on the box.
 *
 * Why the first of these matters: a fresh panel is open for registration and
 * its first account becomes the administrator. A server Central built has a
 * public address before its owner ever opens it, so whoever got there first
 * owned the panel — reproduced on a fresh install the same day.
 */

/** @return array{0: int, 1: string} */
function runCreateAdmin(string $username, string $stdin): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['--username' => $username]);
    $input->setStream($stream);

    $command = app(PanelCreateAdmin::class);
    $command->setLaravel(app());
    $command->setApplication(new ConsoleApplication);

    $output = new BufferedOutput;
    $status = $command->run($input, $output);

    return [$status, $output->fetch()];
}

/** One function out of install.sh, as source. */
function installerFn(string $name): string
{
    preg_match('/^'.preg_quote($name, '/').'\(\) \{\n.*?^\}\n/ms', installerSource(), $m);

    expect($m)->not->toBeEmpty("install.sh has no {$name}()");

    return $m[0];
}

/** install.sh without its whole-line comments — what it runs, not what it says. */
function installerCode(): string
{
    return implode("\n", array_filter(
        preg_split('/\R/', installerSource()) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

describe('artisan panel:create-admin', function () {
    it('creates the administrator with every permission, and closes registration', function () {
        [$status, $out] = runCreateAdmin('central-admin', "Random-Strong-Pass-42\n");

        expect($status)->toBe(0);

        $user = User::where('username', 'central-admin')->first();
        expect($user)->not->toBeNull()
            ->and($user->is_admin)->toBeTrue()
            ->and(Hash::check('Random-Strong-Pass-42', $user->password))->toBeTrue()
            ->and($user->roles()->where('slug', AdministratorRole::SLUG)->exists())->toBeTrue()
            ->and($out)->not->toContain('Random-Strong-Pass-42');

        // The door the attacker would have used.
        $this->postJson('/api/auth/register', [
            'name' => 'Intruder', 'username' => 'intruder', 'password' => 'Intruder-Pass-123', 'password_confirmation' => 'Intruder-Pass-123',
        ])->assertForbidden();
    });

    it('keeps spaces in the password and drops only the herestring\'s line ending', function () {
        runCreateAdmin('spaced', "  pass with spaces  \n");

        expect(Hash::check('  pass with spaces  ', User::where('username', 'spaced')->value('password')))->toBeTrue();
    });

    it('refuses a bad username or password without creating anything, and never echoes the password', function (string $username, string $password) {
        [$status, $out] = runCreateAdmin($username, $password."\n");

        expect($status)->toBe(1)->and(User::count())->toBe(0);

        if (trim($password) !== '') {
            expect($out)->not->toContain(trim($password));
        }
    })->with([
        'short password' => ['admin', 'short-pw'],
        'control character' => ['admin', "has\ttab-in-it-0"],
        'username with a space' => ['bad name', 'Long-Enough-Pass-1'],
        'username too short' => ['ab', 'Long-Enough-Pass-1'],
        'no password' => ['admin', ''],
    ]);

    it('is safe to run again: the same administrator is left exactly as it is', function () {
        runCreateAdmin('central-admin', "First-Password-111\n");
        [$status, $out] = runCreateAdmin('central-admin', "Other-Password-222\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('already exists')
            ->and(User::count())->toBe(1)
            ->and(Hash::check('First-Password-111', User::first()->password))->toBeTrue();
    });

    it('never adds a second administrator to a panel that already has one', function () {
        runCreateAdmin('first', "First-Password-111\n");
        [$status, $out] = runCreateAdmin('second', "Second-Password-222\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('An administrator already exists')
            ->and(User::pluck('username')->all())->toBe(['first']);
    });
});

describe('install.sh', function () {
    it('takes the password from the environment only, never a flag', function () {
        $code = installerCode();

        expect($code)->not->toContain('--admin-password')
            ->and($code)->toContain('PANEL_ADMIN_PASSWORD="${PANEL_ADMIN_PASSWORD:-}"');
    });

    it('hands the password to artisan on standard input, and nowhere else', function () {
        $uses = array_values(array_filter(
            preg_split('/\R/', installerCode()) ?: [],
            fn (string $line): bool => str_contains($line, '$PANEL_ADMIN_PASSWORD') || str_contains($line, '${PANEL_ADMIN_PASSWORD'),
        ));

        // The default, the validation, and the one herestring. Anything else —
        // an argument, a log line, a progress field — is a leak.
        foreach ($uses as $line) {
            expect(
                str_contains($line, 'PANEL_ADMIN_PASSWORD="${PANEL_ADMIN_PASSWORD:-}"')
                || str_contains($line, '<<<"$PANEL_ADMIN_PASSWORD"')
                || preg_match('/^\s*(if|\[\[|\(\()/', $line) === 1
            )->toBeTrue("unexpected use of the password: {$line}");
        }

        expect(implode("\n", $uses))->toContain('<<<"$PANEL_ADMIN_PASSWORD"');
        expect(installerFn('send_progress'))->not->toContain('PANEL_ADMIN_PASSWORD')
            ->and(installerFn('write_details_file'))->not->toContain('PANEL_ADMIN_PASSWORD');
    });

    it('creates the administrator right after the migration, before the panel can be reached', function () {
        $backend = installerFn('setup_backend');

        expect(strpos($backend, 'artisan panel:create-admin'))->toBeGreaterThan(strpos($backend, 'artisan migrate --force'))
            ->and(strpos($backend, 'artisan panel:create-admin'))->toBeLessThan(strpos($backend, 'artisan db:seed'));

        // And setup_backend runs before the frontend is built and the services start.
        $main = installerFn('main');
        expect(strpos($main, 'setup_backend'))->toBeLessThan(strpos($main, 'build_frontend'))
            ->and(strpos($main, 'setup_backend'))->toBeLessThan(strpos($main, 'install_services'));
    });

    it('signs progress with the token from a header file, never the command line', function () {
        $fn = installerFn('send_progress');

        expect($fn)->toContain('-H @"${dir}/header"')
            ->and($fn)->toContain('umask 077')
            ->and(preg_match('/curl[^\n]*Authorization/', $fn))->toBe(0);
    });
});

/*
 * The shell itself, run for real: the reporting function against a receiver on
 * this machine, and the input checks against the cases a caller can get wrong.
 */
function installerFunctions(string ...$names): string
{
    return implode("\n", array_map(fn (string $name) => installerFn($name), $names));
}

it('reports each step to Central as JSON, signed, with the escaping a title needs', function () {
    $dir = sys_get_temp_dir().'/central-progress-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/router.php', '<?php file_put_contents(__DIR__."/got.jsonl", json_encode(["auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null, "body" => json_decode(file_get_contents("php://input"), true)])."\n", FILE_APPEND); http_response_code(204);');
    $port = random_int(20000, 40000);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $dir.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400_000);

    $script = installerFunctions('json_str', 'one_line', 'send_progress').'
        PROGRESS_READY=1; DRY_RUN=0; CENTRAL_TOKEN="tok-xyz"; PROGRESS_URL="http://127.0.0.1:'.$port.'/p"
        STEP_NO=4; STEP_TOTAL=18; CURRENT_STEP=$\'Installing "nginx" \\\\ redis\'; INSTALL_STARTED=1700000000; LOG_FILE=/var/log/install.log
        branding_name() { echo Panel; }; installed_version() { echo 1.2.3; }
        SCHEME=https; PANEL_HOST=p.test; API_HOST=a.test; STACK=lemp; WEB_SERVER=nginx; PANEL_ADMIN_USERNAME=boss
        PANEL_ADMIN_PASSWORD=never-sent-anywhere
        send_progress installing; sleep 1
        send_progress installed';
    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
    proc_terminate($server);

    $got = array_map(fn ($line) => json_decode($line, true), file($dir.'/got.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
    $raw = (string) file_get_contents($dir.'/got.jsonl');
    exec('rm -rf '.escapeshellarg($dir));

    expect($code)->toBe(0)
        ->and($got)->toHaveCount(2)
        ->and($got[0]['auth'])->toBe('Bearer tok-xyz')
        ->and($got[0]['body'])->toMatchArray(['state' => 'installing', 'step' => 4, 'total' => 18, 'title' => 'Installing "nginx" \\ redis'])
        ->and($got[1]['body'])->toMatchArray(['state' => 'installed', 'panel_url' => 'https://p.test', 'api_url' => 'https://a.test', 'stack' => 'lemp', 'admin_username' => 'boss'])
        ->and($raw)->not->toContain('never-sent-anywhere');
});

it('checks Central\'s inputs before anything happens', function (array $env, ?string $error) {
    $assign = implode('; ', array_map(fn ($k, $v) => $k.'='.escapeshellarg($v), array_keys($env), $env));
    $script = installerFunctions('validate_central_inputs').'
        die() { echo "DIE: $*"; exit 3; }
        PANEL_ADMIN_USERNAME=""; PANEL_ADMIN_PASSWORD=""; PROGRESS_URL=""; CENTRAL_TOKEN=""; PROGRESS_READY=0
        '.$assign.'
        validate_central_inputs; echo "OK ready=$PROGRESS_READY"';
    exec('bash -c '.escapeshellarg($script).' 2>&1', $out);
    $said = implode("\n", $out);

    if ($error === null) {
        expect($said)->toStartWith('OK');
    } else {
        expect($said)->toContain('DIE: ')->toContain($error);
    }
})->with([
    'nothing set: as today' => [[], null],
    'admin pair' => [['PANEL_ADMIN_USERNAME' => 'boss', 'PANEL_ADMIN_PASSWORD' => 'Long-Enough-Pass-1'], null],
    'username alone' => [['PANEL_ADMIN_USERNAME' => 'boss'], 'set both'],
    'password alone' => [['PANEL_ADMIN_PASSWORD' => 'Long-Enough-Pass-1'], 'set both'],
    'bad username' => [['PANEL_ADMIN_USERNAME' => 'b o s s', 'PANEL_ADMIN_PASSWORD' => 'Long-Enough-Pass-1'], 'username'],
    'short password' => [['PANEL_ADMIN_USERNAME' => 'boss', 'PANEL_ADMIN_PASSWORD' => 'short'], '12-128'],
    'password with a newline' => [['PANEL_ADMIN_USERNAME' => 'boss', 'PANEL_ADMIN_PASSWORD' => "Long-Enough\nPass-1"], 'control'],
    'https progress with token' => [['PROGRESS_URL' => 'https://central.test/p', 'CENTRAL_TOKEN' => 't'], null],
    'progress to this machine over http' => [['PROGRESS_URL' => 'http://127.0.0.1:9000/p', 'CENTRAL_TOKEN' => 't'], null],
    'plain http elsewhere' => [['PROGRESS_URL' => 'http://central.test/p', 'CENTRAL_TOKEN' => 't'], 'https'],
    'progress without a token' => [['PROGRESS_URL' => 'https://central.test/p'], 'CENTRAL_TOKEN'],
]);
