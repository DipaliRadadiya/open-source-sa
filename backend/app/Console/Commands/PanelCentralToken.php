<?php

namespace App\Console\Commands;

use App\Services\Server\CentralTokenManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Store the central-management token handed to `install.sh`.
 *
 * A real command instead of shell in `install.sh`; the DB logic lives in CentralTokenManager.
 *
 * **The token is read from STDIN, never from an argument.** An argument is world-readable in
 * `ps` while the process lives, and the installer echoes failing commands into its log.
 */
class PanelCentralToken extends Command
{
    protected $signature = 'panel:central-token';

    protected $description = 'Store the central-management token, read from standard input';

    public function handle(CentralTokenManager $tokens): int
    {
        // `trim`: a herestring's newline would make every comparison central makes fail.
        $token = trim($this->rawInput());

        if ($token === '') {
            $this->error('No token on standard input. Pipe it in, e.g. artisan panel:central-token <<<"$TOKEN".');

            return self::FAILURE;
        }

        try {
            $tokens->store($token);
        } catch (Throwable $e) {
            // The message, never the token: this output goes to the install log.
            $this->error('Could not store the central token: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Central management token stored.');

        return self::SUCCESS;
    }

    /**
     * Everything on standard input.
     *
     * The console input's own stream when one is set (Symfony's seam; keeps tests from blocking
     * on `php://stdin`), otherwise `php://stdin`, which is what the installer uses.
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
