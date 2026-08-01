<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * A plain scheduled reboot — restart the machine every day, week or month,
 * whether or not anything asked for it.
 *
 * Distinct from the `updates` group, which is unattended-upgrades' own
 * auto-reboot: that one only fires *when a reboot is required* after an
 * upgrade, and has no frequency at all. Both exist because they answer
 * different questions — "restart when a patch needs it" and "restart on a
 * cadence" — and merging them would mean one of the two lying about what it
 * does.
 *
 * There is no free-form cron expression here, deliberately. Every other
 * scheduling surface in the panel takes one; this one reboots the server, and
 * an arbitrary expression is how somebody writes `* * * * *` and never gets
 * back in.
 *
 * Times are in the **server's own timezone**, which is what cron uses and
 * what the user set two fields higher on the same screen. Silently converting
 * to UTC is how a 3am maintenance window fires at 8am.
 */
class RebootScheduleSettings implements SettingGroup
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public function __construct(private ManagedFile $files) {}

    public function key(): string
    {
        return 'reboot_schedule';
    }

    public function available(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $expression = $this->currentExpression();

        $values = [
            'enabled' => $expression !== null,
            'frequency' => 'daily',
            'hour' => (int) config('server.reboot_schedule.default_hour', 3),
            'day_of_week' => 0,
            'day_of_month' => 1,
            // Named so nobody has to guess. cron runs in server-local time;
            // reporting it removes the "why did it fire an hour early" ticket.
            'timezone' => $this->timezone(),
            'next_run' => null,
            'next_run_human' => null,
        ];

        if ($expression === null) {
            return $values;
        }

        return [...$values, ...$this->describe($expression), ...$this->nextRun($expression)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $path = $this->path();

        if (! ($data['enabled'] ?? false)) {
            // Removed, not commented out: a disabled schedule that still sits
            // in /etc/cron.d is one uncomment away from an unexpected reboot.
            $result = $this->files->delete($path, ['feature' => 'setting', 'group' => 'reboot_schedule']);

            if ($result->failed()) {
                throw new SettingOperationException($result->reference);
            }

            return;
        }

        $expression = $this->expressionFor($data);

        $contents = "# Managed by the panel — edit via Settings, not by hand.\n"
            ."SHELL=/bin/sh\n"
            ."PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n"
            // `shutdown -r` rather than `reboot`: it gives logged-in users the
            // wall message and lets services stop cleanly.
            //
            // The activity entry is written first, and separated with `;`
            // rather than `&&` on purpose: a reboot the administrator
            // scheduled must happen even if the panel's database is down. `&&`
            // would make an audit record a precondition of the restart, so a
            // logging failure would silently cancel maintenance — the opposite
            // of what an audit trail is for.
            ."{$expression} root {$this->logCommand()} ; /sbin/shutdown -r +1 \"Scheduled reboot from the server panel\"\n";

        $result = $this->files->put($path, $contents, ['feature' => 'setting', 'group' => 'reboot_schedule']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }

    /**
     * The command that records the reboot in the activity log.
     *
     * Run through `runuser` as the account the panel itself runs as, not as
     * root. Artisan writes to storage/ — logs, cache — and a root-owned file
     * in there breaks the panel on its next request. The cron line is root so
     * that `shutdown` works; only this half drops privileges.
     *
     * The PHP binary is named explicitly rather than taken from PHP_BINARY,
     * which under FPM is the FPM binary and cannot run artisan.
     */
    private function logCommand(): string
    {
        $php = str_replace(
            '{version}',
            PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            (string) config('server.php_binary_pattern', '/usr/bin/php{version}'),
        );

        return sprintf(
            'runuser -u %s -- %s %s server:log-scheduled-reboot',
            escapeshellarg($this->panelUser()),
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
        );
    }

    /**
     * The OS account this process is running as — which is the account that
     * owns the panel's files, so it is the one that may write to storage/.
     */
    private function panelUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && ($user['name'] ?? '') !== '') {
                return (string) $user['name'];
            }
        }

        return (string) (get_current_user() ?: 'root');
    }

    /**
     * The cron expression for a validated frequency.
     *
     * @param  array<string, mixed>  $data
     */
    private function expressionFor(array $data): string
    {
        $hour = (int) $data['hour'];
        $minute = (int) config('server.reboot_schedule.minute', 0);

        return match ($data['frequency']) {
            'weekly' => sprintf('%d %d * * %d', $minute, $hour, (int) ($data['day_of_week'] ?? 0)),
            'monthly' => sprintf('%d %d %d * *', $minute, $hour, (int) ($data['day_of_month'] ?? 1)),
            default => sprintf('%d %d * * *', $minute, $hour),
        };
    }

    /**
     * Read back what is actually on disk rather than what we last wrote —
     * the file is editable by root, and the screen should show the truth.
     */
    private function currentExpression(): ?string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, 'shutdown')) {
                continue;
            }

            // `m h dom mon dow user command`
            $fields = preg_split('/\s+/', $line) ?: [];

            if (count($fields) >= 5) {
                return implode(' ', array_slice($fields, 0, 5));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $expression): array
    {
        [$minute, $hour, $dayOfMonth, , $dayOfWeek] = array_pad(preg_split('/\s+/', $expression) ?: [], 5, '*');

        return [
            'frequency' => match (true) {
                $dayOfWeek !== '*' => 'weekly',
                $dayOfMonth !== '*' => 'monthly',
                default => 'daily',
            },
            'hour' => is_numeric($hour) ? (int) $hour : 0,
            'day_of_week' => is_numeric($dayOfWeek) ? (int) $dayOfWeek : 0,
            'day_of_month' => is_numeric($dayOfMonth) ? (int) $dayOfMonth : 1,
        ];
    }

    /**
     * @return array{next_run: string|null, next_run_human: string|null}
     */
    private function nextRun(string $expression): array
    {
        try {
            $next = CarbonImmutable::instance(
                (new CronExpression($expression))->getNextRunDate(CarbonImmutable::now($this->timezone())),
            );
        } catch (Throwable) {
            return ['next_run' => null, 'next_run_human' => null];
        }

        return [
            'next_run' => $next->format('d-m-Y H:i:s'),
            'next_run_human' => $next->diffForHumans(),
        ];
    }

    private function timezone(): string
    {
        return trim((string) @file_get_contents('/etc/timezone')) ?: (string) config('app.timezone', 'UTC');
    }

    private function path(): string
    {
        return rtrim((string) config('server.cron_d', '/etc/cron.d'), '/')
            .'/'.trim((string) config('server.reboot_schedule.file', 'panel-reboot'), '/');
    }
}
