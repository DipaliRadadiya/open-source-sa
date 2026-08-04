<?php

namespace App\Console\Commands;

use App\Services\Server\Doctor\Doctor;
use Illuminate\Console\Command;

/**
 * Prove the panel works on this server.
 *
 * Run by install.sh at the end, so the installer cannot report success on a
 * box where the panel is inert — which is precisely how a missing sudo
 * escalation went unnoticed while 1,100 faked tests passed.
 */
class PanelDoctor extends Command
{
    protected $signature = 'panel:doctor {--json : Machine-readable output}';

    protected $description = 'Check that this installation actually works';

    public function handle(Doctor $doctor): int
    {
        $report = $doctor->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>Checking this installation</>');
        $this->newLine();

        foreach ($report['checks'] as $check) {
            [$mark, $colour] = match ($check['status']) {
                'pass' => ['✓', 'green'],
                'warn' => ['!', 'yellow'],
                default => ['✗', 'red'],
            };

            $this->line(sprintf(
                '  <fg=%s>%s</> %-18s <fg=gray>%s</>',
                $colour,
                $mark,
                $check['title'],
                $check['detail'] ?? '',
            ));

            // Only failures get advice. Printing a fix next to a passing check
            // is noise, and noise is what stops people reading output.
            if ($check['fix'] !== null && $check['status'] !== 'pass') {
                $this->line('      <fg=gray>→ '.$check['fix'].'</>');
            }
        }

        $this->newLine();

        if ($report['healthy']) {
            $this->line(sprintf(
                '  <fg=green;options=bold>Healthy</> — %d passed%s',
                $report['passed'],
                $report['warnings'] > 0 ? ', '.$report['warnings'].' warning(s)' : '',
            ));
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  <fg=red;options=bold>%d check(s) failed</> — the panel will not work correctly until these are fixed.',
            $report['failed'],
        ));
        $this->newLine();

        // Non-zero so install.sh and any CI runner can act on it rather than
        // parsing this text.
        return self::FAILURE;
    }
}
