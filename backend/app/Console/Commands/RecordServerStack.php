<?php

namespace App\Console\Commands;

use App\Services\Server\Capabilities\ServerCapabilities;
use Illuminate\Console\Command;

/**
 * Records which stack the installer laid down.
 *
 * The installer knows this and the panel cannot infer it: detection can see
 * that nginx and PHP are present, but not whether that was a deliberate `lemp`
 * build or a box someone assembled by hand. Without this the stack stays null,
 * which reads as "we did not build this server" — true of a migrated server and
 * a lie about a freshly installed one.
 *
 * A console command rather than an endpoint because it runs during installation,
 * before any user exists to authenticate as.
 */
class RecordServerStack extends Command
{
    protected $signature = 'server:record-stack {stack : lemp, lamp, ols or mern}';

    protected $description = 'Record the stack this server was built with';

    public function handle(ServerCapabilities $capabilities): int
    {
        $stack = (string) $this->argument('stack');

        if (! in_array($stack, ServerCapabilities::stacks(), true)) {
            $this->components->error(
                "Unknown stack [{$stack}]. Expected one of: ".implode(', ', ServerCapabilities::stacks())
            );

            return self::FAILURE;
        }

        $record = $capabilities->recordStack($stack);

        $this->components->info("Recorded stack [{$record->stack}] with web server [{$record->web_server}].");

        return self::SUCCESS;
    }
}
