<?php

namespace App\Console\Commands;

use App\Services\Server\SudoersFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Write the panel's sudo grant from config, or print it for install.sh.
 *
 * Re-run on update so a binary the panel starts needing is not denied on existing servers.
 *
 *   - `--print`  install.sh's configure_sudoers() pipes it to the file; runs before the panel has
 *                a database, so this must not touch one.
 *   - no flags   the `sync_privileges` step of both update flows, as root.
 *
 * **This is the most destructive thing the panel writes.** A sudoers file that does not parse
 * takes `sudo` away from every account, possibly unrecoverable on a remote server. So the new
 * content goes to a temporary file, is validated with `visudo -c`, and only then moved into
 * place; the existing grant is never removed first.
 */
class PanelSudoers extends Command
{
    protected $signature = 'panel:sudoers
        {--print : Write the rendered file to stdout and change nothing}
        {--dry-run : Report whether the file is current, without writing}';

    protected $description = "Render the panel's sudoers grant from config/server.php";

    public function handle(SudoersFile $sudoers): int
    {
        if ($this->option('print')) {
            // Raw output: the caller redirects this into /etc/sudoers.d, so no extra line or banner.
            $this->output->write($sudoers->render());

            return self::SUCCESS;
        }

        $path = $sudoers->path();
        $desired = $sudoers->render();
        $current = is_file($path) ? (string) file_get_contents($path) : null;

        if ($current === $desired) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn($current === null
                ? "{$path} does not exist and would be created."
                : "{$path} is out of date and would be rewritten.");

            return self::SUCCESS;
        }

        // Check "can this file be written", not "is this root": same on a normal server, but
        // testable. Never escalate through sudo: the grant being repaired would have to authorise it.
        if (! is_writable(dirname($path))) {
            $this->error('Cannot write '.$path.'; run this as root.');

            return self::FAILURE;
        }

        return $this->install($path, $desired);
    }

    /**
     * Where visudo is, by absolute path.
     *
     * Not resolved through PATH: /usr/sbin is not on the PATH of every account that runs this.
     *
     * Refusing when it is absent is deliberate: an unvalidated sudoers file is worse than a
     * stale one.
     */
    private function visudo(): ?string
    {
        foreach (['/usr/sbin/visudo', '/usr/bin/visudo'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Validate, then move into place.
     *
     * The temporary file sits next to the target, not in /tmp, so the final rename is atomic.
     */
    private function install(string $path, string $content): int
    {
        $temporary = $path.'.new';

        if (@file_put_contents($temporary, $content) === false) {
            $this->error("Could not write {$temporary}.");

            return self::FAILURE;
        }

        // 0440 before validation: the file is already in /etc/sudoers.d, where sudo reads it.
        @chmod($temporary, 0440);

        $visudo = $this->visudo();

        if ($visudo === null) {
            @unlink($temporary);
            $this->error('visudo was not found, so the grant could not be validated; the existing one is unchanged.');

            return self::FAILURE;
        }

        $check = Process::timeout(30)->run([$visudo, '-cqf', $temporary]);

        if (! $check->successful()) {
            @unlink($temporary);
            $this->error('The generated sudoers file did not validate; the existing grant is unchanged.');
            $this->line(trim($check->errorOutput() ?: $check->output()));

            return self::FAILURE;
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("Could not move {$temporary} into place.");

            return self::FAILURE;
        }

        $this->info("{$path} updated.");

        return self::SUCCESS;
    }
}
