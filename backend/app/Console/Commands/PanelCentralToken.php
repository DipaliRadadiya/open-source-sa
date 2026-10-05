<?php

namespace App\Console\Commands;

use App\Services\Server\CentralTokenManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Store the central-management token handed to `install.sh`.
 *
 * Exists because the installer used to do this itself, in shell, and got it
 * wrong three ways at once — none of which it could report, since the whole
 * block was best-effort and the panel has no screen that shows the token:
 *
 *   1. The token was always empty. It was read as `${CENTRAL_TOKEN}` inside a
 *      SINGLE-quoted `sh -c` string, so the parent shell never expanded it; and
 *      even double-quoted it would have been gone, because sudo resets the
 *      environment by default.
 *   2. The PHP was a parse error. `\\'settings\\'` inside the single-quoted
 *      argument closed the quote rather than escaping anything, so tinker was
 *      handed `\DB::table(\settings\)`.
 *   3. It ran `php "$2" artisan`, where `$2` is the PHP binary — i.e.
 *      `php /usr/bin/php8.4 artisan`. Bare `php` is not even on PATH on the
 *      OpenLiteSpeed stack.
 *
 * So: a real command, with the DB logic in CentralTokenManager where the rest
 * of it already lives, and a test that proves the stored value.
 *
 * **The token is read from STDIN, never from an argument.** An argument is
 * world-readable in `ps` for as long as the process lives, and this installer
 * echoes failing commands into its log. Standard input is visible to neither.
 */
class PanelCentralToken extends Command
{
    protected $signature = 'panel:central-token';

    protected $description = 'Store the central-management token, read from standard input';

    public function handle(CentralTokenManager $tokens): int
    {
        // `trim`, so the newline a shell herestring appends is not part of the
        // credential. A token stored with a trailing newline fails every
        // comparison central makes, and nothing on either side says why.
        $token = trim($this->rawInput());

        if ($token === '') {
            $this->error('No token on standard input. Pipe it in, e.g. artisan panel:central-token <<<"$TOKEN".');

            return self::FAILURE;
        }

        try {
            $tokens->store($token);
        } catch (Throwable $e) {
            // The message, never the token — this output goes to the install
            // log.
            $this->error('Could not store the central token: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Central management token stored.');

        return self::SUCCESS;
    }

    /**
     * Everything on standard input.
     *
     * Taken from the console input's own stream when one is set, and from
     * `php://stdin` otherwise. That is Symfony's seam for exactly this — it is
     * how the question helper reads answers — and it is the difference between
     * a command that can be tested and one that cannot: `php://stdin` under the
     * test runner blocks on a terminal that will never send EOF.
     *
     * The installer sets no stream, so it takes the second path and reads the
     * herestring run() hands it.
     */
    private function rawInput(): string
    {
        $stream = $this->input instanceof StreamableInputInterface
            ? $this->input->getStream()
            : null;

        if ($stream !== null) {
            return (string) stream_get_contents($stream);
        }

        return (string) file_get_contents('php://stdin');
    }
}
