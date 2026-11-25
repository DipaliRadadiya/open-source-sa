<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Support\ServerTimezone;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * A plain scheduled reboot: restart the machine every day, week or month.
 *
 * Distinct from the `updates` group (unattended-upgrades' auto-reboot), which only fires
 * when a reboot is required and has no frequency.
 *
 * No free-form cron expression on purpose: one bad `* * * * *` would reboot-loop the server.
 *
 * Times are in the server's own timezone (what cron uses); never convert to UTC.
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
            // cron runs in server-local time; report it.
            'timezone' => $this->timezone(),
            'next_run' => null,
            'next_run_human' => null,
        ];

        if ($expression === null) {
            return [...$values, ...$this->remembered()];
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
            $this->remember($data);

            // Removed, not commented out: a leftover file is one uncomment from a surprise reboot.
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
            // `shutdown -r` rather than `reboot`: logged-in users get the wall message and
            // services stop cleanly. `now`, not `+1`: it restarts at the time shown.
            // The log command is separated with `;`, not `&&`: the reboot must happen even if
            // the panel's database is down.
            ."{$expression} root {$this->logCommand()} ; /sbin/shutdown -r now \"Scheduled reboot from the server panel\"\n";

        $result = $this->files->put($path, $contents, ['feature' => 'setting', 'group' => 'reboot_schedule']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }

    /**
     * The command that records the reboot in the activity log.
     *
     * Run through `runuser` as the panel's own account, not root: a root-owned file in
     * storage/ breaks the panel. Only this half drops privileges; `shutdown` needs root.
     *
     * The PHP binary is named explicitly: PHP_BINARY under FPM cannot run artisan. The one
     * install.sh recorded (PANEL_PHP_BIN) wins over the distro pattern, which does not exist
     * on OpenLiteSpeed.
     */
    private function logCommand(): string
    {
        $php = (string) config('panel_update.php_binary', '');

        if ($php === '') {
            $php = str_replace(
                '{version}',
                PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                (string) config('server.php_binary_pattern', '/usr/bin/php{version}'),
            );
        }

        return sprintf(
            'runuser -u %s -- %s %s server:log-scheduled-reboot',
            escapeshellarg($this->panelUser()),
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
        );
    }

    /**
     * The OS account this process runs as: it owns the panel's files and may write storage/.
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
     * Keep a schedule's day and hour when it is switched off.
     *
     * The cron file is deleted on disable (see apply()), so the day and hour are saved
     * separately. What the client sent wins; otherwise what the file said. Best effort:
     * failing to remember must never stop the schedule being switched off.
     *
     * @param  array<string, mixed>  $data
     */
    private function remember(array $data): void
    {
        $expression = $this->currentExpression();
        $values = $expression !== null ? $this->describe($expression) : [];

        if (in_array($data['frequency'] ?? null, self::FREQUENCIES, true)) {
            $values = array_filter([
                'frequency' => $data['frequency'],
                'hour' => isset($data['hour']) ? (int) $data['hour'] : ($values['hour'] ?? null),
                'day_of_week' => isset($data['day_of_week']) ? (int) $data['day_of_week'] : ($values['day_of_week'] ?? null),
                'day_of_month' => isset($data['day_of_month']) ? (int) $data['day_of_month'] : ($values['day_of_month'] ?? null),
            ], fn ($value) => $value !== null);
        }

        if ($values === []) {
            return;
        }

        try {
            File::ensureDirectoryExists(dirname($this->rememberedPath()));
            File::put($this->rememberedPath(), (string) json_encode($values));
        } catch (Throwable) {
            // Forgetting a preference is not worth failing the switch-off.
        }
    }

    /**
     * The day/hour of the last switched-off schedule, re-validated: a hand edit must not
     * put an hour of 99 on the screen.
     *
     * @return array<string, mixed>
     */
    private function remembered(): array
    {
        $path = $this->rememberedPath();
        $saved = is_file($path) ? json_decode((string) File::get($path), true) : null;

        if (! is_array($saved)) {
            return [];
        }

        return array_filter([
            'frequency' => in_array($saved['frequency'] ?? null, self::FREQUENCIES, true) ? $saved['frequency'] : null,
            'hour' => is_int($saved['hour'] ?? null) && $saved['hour'] >= 0 && $saved['hour'] <= 23 ? $saved['hour'] : null,
            'day_of_week' => is_int($saved['day_of_week'] ?? null) && $saved['day_of_week'] >= 0 && $saved['day_of_week'] <= 6 ? $saved['day_of_week'] : null,
            'day_of_month' => is_int($saved['day_of_month'] ?? null) && $saved['day_of_month'] >= 1 && $saved['day_of_month'] <= 28 ? $saved['day_of_month'] : null,
        ], fn ($value) => $value !== null);
    }

    private function rememberedPath(): string
    {
        return (string) config('server.reboot_schedule.remembered', storage_path('app/reboot-schedule.json'));
    }

    /**
     * Read back what is on disk, not what we last wrote: root can edit the file.
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

    /**
     * The zone cron reads this file in. ServerTimezone, not /etc/timezone alone: newer Ubuntu
     * no longer ships that file.
     */
    private function timezone(): string
    {
        return ServerTimezone::get();
    }

    private function path(): string
    {
        return rtrim((string) config('server.cron_d', '/etc/cron.d'), '/')
            .'/'.trim((string) config('server.reboot_schedule.file', 'panel-reboot'), '/');
    }
}
