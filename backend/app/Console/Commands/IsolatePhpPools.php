<?php

namespace App\Console\Commands;

use App\Services\Server\Php\PoolIsolator;
use Illuminate\Console\Command;

/**
 * Give every PHP site its own FPM pool.
 *
 * New sites are provisioned with one, so this is for the sites that predate
 * that: adopted from another panel, created before pool isolation shipped, or
 * left behind by a `create_php_pool` step that failed. Until it runs, those
 * sites are still served by the shared pool as the web server's own account,
 * which is what lets one compromised site read every other site's `.env`.
 *
 * Exits 0 even when individual sites fail. A site whose pool would not write
 * has been left exactly as it was and is still serving; failing the command
 * would abort an otherwise-good panel update over it. The names are printed
 * so it is visible rather than silent.
 */
class IsolatePhpPools extends Command
{
    protected $signature = 'php:isolate-all';

    protected $description = 'Give every PHP site its own FPM pool, converting any left on the shared one';

    public function handle(PoolIsolator $isolator): int
    {
        if (! $isolator->supported()) {
            // OpenLiteSpeed runs LSPHP and has no pools to give.
            $this->info('This server does not use PHP-FPM pools. Nothing to do.');

            return self::SUCCESS;
        }

        $result = $isolator->isolateAll();

        if ($result['total'] === 0) {
            $this->info('Every PHP site already has its own pool.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d site(s) were on the shared pool: %d isolated, %d failed.',
            $result['total'],
            $result['isolated'],
            count($result['failed']),
        ));

        foreach ($result['failed'] as $failure) {
            $this->warn(sprintf(
                'Left on the shared pool: %s (#%d) — %s',
                $failure['name'],
                $failure['id'],
                $failure['reason'],
            ));
        }

        return self::SUCCESS;
    }
}
