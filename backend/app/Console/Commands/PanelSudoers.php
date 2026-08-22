<?php

namespace App\Console\Commands;

use App\Services\Server\SudoersFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Write the panel's sudo grant from config, or print it for install.sh.
 *
 * Exists because the grant used to be laid down once, at install, and never
 * again — so a binary the panel started needing was denied on every server
 * that was not freshly installed. `touch`, `certbot` and `mysqldump` each
 * broke a shipped feature that way inside a single week, and the only cure
 * was re-running install.sh by hand on every box.
 *
 * Two callers, one renderer:
 *
 *   - `--print`  install.sh's configure_sudoers(), which pipes it to the file
 *                itself. It runs before the panel has a database, so this must
 *                not touch one.
 *   - no flags   the `sync_privileges` step of both update flows, as root.
 *
 * **This is the most destructive thing the panel writes.** A sudoers file that
 * does not parse takes `sudo` away from every account on the machine — the
 * operator's included, on a remote server. So the new content is written to a
 * temporary file, validated with `visudo -c`, and only then moved into place.
 * The existing grant is never removed first: a failure here has to leave the
 * server exactly as it was, because a server with a stale grant still works
 * and a server with no sudo may not be recoverable without console access.
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
            // Raw, with no trailing newline added by the console: the caller
            // redirects this straight into /etc/sudoers.d, and an extra line
            // or a decorated info() banner would end up in the file.
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

        // Asked as "can this file be written", not "is this root". They are
        // the same question on a normal server and it is the honest one to
        // ask — but it is also the only one that can be exercised by a test,
        // and the write below is the most destructive thing the panel does.
        // A guard that forces the whole path to be taken on trust is how the
        // prune bug in PanelReleases survived five passing assertions.
        //
        // Escalating through sudo is not an option and never will be: the
        // grant being repaired is the one that would have to authorise the
        // repair.
        if (! is_writable(dirname($path))) {
            $this->error('Cannot write '.$path.'; run this as root.');

            return self::FAILURE;
        }

        return $this->install($path, $desired);
    }

    /**
     * Where visudo is, by absolute path.
     *
     * Not resolved through PATH. visudo is an administrative binary and lives
     * in /usr/sbin, which is on root's secure_path but not on the PATH of the
     * account php-fpm runs as — so a bare name works when an operator runs
     * this by hand and not when the update does, or the other way round,
     * depending on the box. Rather than validate through whatever a PATH
     * happens to offer, name the two places it is.
     *
     * Refusing when it is absent is deliberate: an unvalidated sudoers file is
     * the one outcome worse than a stale one.
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
     * The temporary file is created next to the target rather than in /tmp, so
     * the final step is a rename within one filesystem — atomic, with no window
     * in which the file is half-written and being read by a sudo invocation.
     */
    private function install(string $path, string $content): int
    {
        $temporary = $path.'.new';

        if (@file_put_contents($temporary, $content) === false) {
            $this->error("Could not write {$temporary}.");

            return self::FAILURE;
        }

        // 0440 before validation, not after: between these two lines the file
        // is already in /etc/sudoers.d, where sudo will read anything present.
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
