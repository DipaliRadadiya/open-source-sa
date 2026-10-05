<?php

use App\Console\Commands\PanelCentralToken;
use App\Services\Server\CentralTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/*
 * The central token install.sh is handed.
 *
 * The installer used to store this itself, in shell, and had three defects at
 * once -- none of which it could report, because the block was best-effort and
 * no screen shows the token:
 *
 *   1. The token was ALWAYS empty: read as `${CENTRAL_TOKEN}` inside a
 *      SINGLE-quoted `sh -c` string, so the parent never expanded it -- and
 *      double-quoted it would still have been empty, because sudo resets the
 *      environment.
 *   2. Invalid PHP: `\\'settings\\'` inside the single-quoted argument closed
 *      the quote rather than escaping, so tinker was handed
 *      `\DB::table(\settings\)->where(...)`.
 *   3. It ran `php "$2" artisan` where `$2` is the PHP binary, i.e.
 *      `php /usr/bin/php8.4 artisan`. Bare `php` is not on PATH at all on the
 *      OpenLiteSpeed stack.
 *
 * So it had never worked on any install, and the installer said "central
 * management token stored" every time.
 */

/** Run the command with `$stdin` on the console input's stream. */
function runCentralToken(string $stdin): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput([]);
    $input->setStream($stream);

    $command = app(PanelCentralToken::class);
    $command->setLaravel(app());
    $command->setApplication(new ConsoleApplication);

    $output = new BufferedOutput;
    $status = $command->run($input, $output);

    return [$status, $output->fetch()];
}

/**
 * install.sh with whole-line comments removed.
 *
 * The block carries a comment explaining each of the three defects it replaced,
 * quoting the old code — so asserting "install.sh does not contain `tinker`"
 * against the raw source matches the explanation of the bug rather than the
 * bug. These assertions are about what the script *runs*.
 */
function installerCodeLines(): string
{
    $lines = array_filter(
        preg_split('/\R/', installerSource()) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    );

    return implode("\n", $lines);
}

describe('artisan panel:central-token', function () {

    it('stores the token it is given on standard input', function () {
        [$status] = runCentralToken("sv_central_from_install\n");

        expect($status)->toBe(0)
            ->and(DB::table('settings')->where('id', 1)->value('central_token'))
            ->toBe('sv_central_from_install');
    });

    it('creates the settings row when nothing has ever saved settings', function () {
        // A fresh install stores the token before any settings exist, which is
        // the branch the old inline PHP got to via an `insert`. If this took the
        // update path only, every real install would store nothing and say it
        // had.
        expect(DB::table('settings')->count())->toBe(0);

        runCentralToken('sv_central_fresh');

        expect(DB::table('settings')->where('id', 1)->value('central_token'))
            ->toBe('sv_central_fresh');
    });

    it('strips the newline a shell herestring appends', function () {
        // `<<<"$TOKEN"` adds one. Stored with it, the token fails every
        // comparison central makes and neither side can say why.
        runCentralToken("sv_central_trailing\n");

        expect(DB::table('settings')->where('id', 1)->value('central_token'))
            ->toBe('sv_central_trailing');
    });

    it('is idempotent: the same token twice converges', function () {
        // The installer is re-run after a failure, and re-running it must not
        // rotate the credential out from under central.
        runCentralToken('sv_central_same');
        [$status] = runCentralToken('sv_central_same');

        expect($status)->toBe(0)
            ->and(DB::table('settings')->where('id', 1)->value('central_token'))->toBe('sv_central_same')
            ->and(DB::table('settings')->count())->toBe(1);
    });

    it('replaces an older token rather than adding a second row', function () {
        runCentralToken('sv_central_first');
        runCentralToken('sv_central_second');

        expect(DB::table('settings')->where('id', 1)->value('central_token'))->toBe('sv_central_second')
            ->and(DB::table('settings')->count())->toBe(1);
    });

    it('fails loudly on empty input instead of reporting a token it did not store', function () {
        // This is the state every install was actually in. Reported as success,
        // so nobody looked for two years' worth of installs.
        [$status, $output] = runCentralToken('');

        expect($status)->toBe(1)
            ->and($output)->toContain('No token')
            ->and(DB::table('settings')->where('id', 1)->value('central_token'))->toBeNull();
    });

    it('treats whitespace-only input as empty, and says which problem it is', function () {
        // The message matters, and it is what makes the command's own trim
        // load-bearing rather than belt-and-braces: without it this input
        // reaches the manager, which refuses it too — but as "central token
        // must not be empty", when the operator's actual problem is that
        // nothing arrived on stdin. One of those tells them where to look.
        [$status, $output] = runCentralToken("  \n\t ");

        expect($status)->toBe(1)
            ->and($output)->toContain('No token on standard input')
            ->and(DB::table('settings')->where('id', 1)->value('central_token'))->toBeNull();
    });

    it('never writes the token to the log or to its own output', function () {
        // The command's output goes straight into the install log, and the log
        // is world-readable long enough to matter. Asserted on both.
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $token = 'sv_central_must_not_appear';
        [$status, $output] = runCentralToken($token);

        expect($status)->toBe(0)
            ->and($output)->not->toContain($token)
            ->and(implode("\n", $logged))->not->toContain($token);
    });

    it('clears the used-at stamp, since a reinstall has not been used yet', function () {
        runCentralToken('sv_central_used');
        DB::table('settings')->where('id', 1)->update(['central_token_used_at' => now()]);

        runCentralToken('sv_central_used');

        expect(DB::table('settings')->where('id', 1)->value('central_token_used_at'))->toBeNull();
    });
});

describe('CentralTokenManager::store', function () {

    it('refuses a blank token rather than silently storing nothing', function () {
        expect(fn () => app(CentralTokenManager::class)->store('   '))
            ->toThrow(InvalidArgumentException::class);
    });

    it('leaves status() reporting enabled, with the value masked', function () {
        // Proof it went through the same path the rest of the feature reads,
        // rather than writing a column the API cannot see.
        $tokens = app(CentralTokenManager::class);
        $tokens->store('sv_central_statuscheck');

        $status = $tokens->status();

        expect($status['enabled'])->toBeTrue()
            ->and($status)->not->toHaveKey('central_token')
            ->and($status['masked'])->not->toBe('sv_central_statuscheck');
    });

    it('stores a token that validate() then accepts', function () {
        // The whole point of the feature: central calls this server's API with
        // the value it gave the installer. Checked by using it, not by reading
        // the column -- a column that matches and a credential that works are
        // two different claims.
        $tokens = app(CentralTokenManager::class);
        $tokens->store('sv_central_roundtrip');

        $tokens->validate('sv_central_roundtrip');
    })->throwsNoExceptions();
});

describe('install.sh hands the token over safely', function () {

    it('passes the token on stdin, never as an argument', function () {
        $source = installerCodeLines();

        // An argument is readable by every account on the box via `ps` for as
        // long as the command runs, and run() echoes a failing command into the
        // log. Both of those are how a credential leaks without anyone's code
        // being wrong.
        expect($source)->toContain('artisan panel:central-token')
            ->and($source)->toContain('<<<"$CENTRAL_TOKEN"')
            ->and($source)->not->toContain('panel:central-token "$CENTRAL_TOKEN"')
            ->and($source)->not->toContain('panel:central-token --token');
    });

    it('no longer shells out to tinker with hand-escaped PHP', function () {
        $source = installerCodeLines();

        expect($source)->not->toContain('artisan tinker')
            ->and($source)->not->toContain("\\\\'settings\\\\'");
    });

    it('offers --central-token and documents the env var in --help', function () {
        $source = installerSource();   // --help text IS prose, read it whole

        expect($source)->toContain('--central-token=*)')
            ->and($source)->toContain('--central-token=TOKEN')
            ->and($source)->toContain('CENTRAL_TOKEN environment variable');
    });

    it('runs the PHP binary directly, not `php $binary`', function () {
        // `php "$2" artisan` ran `php /usr/bin/php8.4 artisan`, and bare `php`
        // is not on PATH on the OLS stack at all.
        $source = installerCodeLines();

        expect($source)->not->toContain('php "$2" artisan');
    });
});
