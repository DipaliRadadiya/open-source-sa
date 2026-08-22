<?php

namespace App\Console\Commands;

use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Move this installation onto the release layout.
 *
 * **Dry runs by default.** Every other command in the panel does the thing you
 * asked for; this one does not, and the asymmetry is deliberate. An update
 * runs on a server whose owner has asked for it to change. This runs on a
 * server that is working, and the design doc calls it riskier than the update
 * itself for exactly that reason. `--commit` is the word that makes it act.
 *
 * It is not wired into `install.sh` and no update calls it. Migration is a
 * decision someone makes, on a box they have looked at, with a backup they
 * know about.
 */
class MigratePanelLayout extends Command
{
    protected $signature = 'panel:migrate-layout
        {--commit : Actually perform the migration, rather than describing it}';

    protected $description = 'Move this panel from a single checkout to release directories';

    public function handle(PanelMigration $migration): int
    {
        $problems = $migration->preflight();

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error(__('panel_update.migration.'.$problem));
            }

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $plan = $migration->plan(now()->format('Ymd-His'));

        if (! $commit) {
            $this->warn('Dry run — nothing will change. Re-run with --commit to migrate.');
        }

        foreach ($plan as $step) {
            $this->line('');
            $this->info($step['step']);

            if ($step['step'] === 'rewrite_env') {
                $result = $this->rewriteEnv($migration, $commit);

                if ($result !== self::SUCCESS) {
                    return $result;
                }

                continue;
            }

            foreach ($step['commands'] as $command) {
                $this->line('  '.$command);

                if (! $commit) {
                    continue;
                }

                $result = Process::timeout(600)->run(['/usr/bin/sh', '-c', $command]);

                if (! $result->successful()) {
                    // Stop where it broke rather than pressing on. Past
                    // `move_checkout` this leaves a server that is neither
                    // shape, which is why the message says which step and the
                    // backup from step one exists.
                    $this->error("Failed at {$step['step']}: ".trim($result->errorOutput() ?: $result->output()));
                    $this->error('The panel is part-migrated. Do not run this again until the cause is understood.');

                    return self::FAILURE;
                }
            }
        }

        $this->line('');
        $this->info($commit ? 'Migrated.' : 'Dry run complete. Nothing changed.');

        return self::SUCCESS;
    }

    /**
     * Rewrite the settings the new layout needs, in the shared `.env`.
     *
     * Keys are replaced in place when present and appended when not, rather
     * than the file being regenerated: it holds the APP_KEY and every
     * credential this panel has, and the only safe edit is the smallest one.
     */
    private function rewriteEnv(PanelMigration $migration, bool $commit): int
    {
        $path = app(PanelLayout::class)->sharedPath().'/.env';
        $settings = $migration->environment();

        foreach ($settings as $key => $value) {
            $this->line("  {$key}={$value}");
        }

        if (! $commit) {
            return self::SUCCESS;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->error("Could not read {$path}; the shared env was not created.");

            return self::FAILURE;
        }

        foreach ($settings as $key => $value) {
            $line = $key.'='.$value;

            $contents = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1
                ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        if (@file_put_contents($path, $contents) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
