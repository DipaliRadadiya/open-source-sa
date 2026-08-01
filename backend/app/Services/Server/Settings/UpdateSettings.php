<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Automatic security updates + auto-reboot (Ubuntu unattended-upgrades),
 * managed via a panel-owned apt.conf.d drop-in (non-destructive — the distro's
 * own 50unattended-upgrades is never touched). `reboot_required` is read from
 * the kernel flag file.
 *
 * The group also reports what the toggle is *doing*: how many updates are
 * waiting, when apt last refreshed, and whether the last unattended run
 * worked. A switch labelled "on" is not evidence that anything has happened,
 * and the failure this surfaces — unattended-upgrades quietly erroring for
 * weeks — is invisible by design otherwise.
 *
 * Every one of those reads is best-effort and returns `null` when it cannot be
 * answered. `null` and `0` mean different things here and the distinction is
 * load-bearing: `0` says "nothing is waiting", `null` says "nobody knows". A
 * failed check reported as `0` would be the same silent-success bug the fields
 * exist to expose.
 */
class UpdateSettings implements SettingGroup
{
    public function __construct(
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    public function key(): string
    {
        return 'updates';
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
        $config = $this->currentConfig();

        return [
            'security_updates_enabled' => ($config['APT::Periodic::Unattended-Upgrade'] ?? '0') === '1',
            'auto_reboot' => ($config['Unattended-Upgrade::Automatic-Reboot'] ?? 'false') === 'true',
            'reboot_time' => $config['Unattended-Upgrade::Automatic-Reboot-Time'] ?? '02:00',
            // Upstream defaults this to true, meaning the box restarts under
            // an administrator who is in the middle of an SSH session. We
            // default it to false and make it a deliberate choice.
            'reboot_with_users' => ($config['Unattended-Upgrade::Automatic-Reboot-WithUsers'] ?? 'false') === 'true',
            'reboot_required' => is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
            ...$this->pending(),
            ...$this->lastRefresh(),
            ...$this->lastUnattendedRun(),
        ];
    }

    /**
     * How many upgrades are waiting, and how many of those are security.
     *
     * `apt-check` prints `updates;security` to **stderr** and nothing at all to
     * stdout, so this reads the error stream on purpose. It is also the cheap
     * way to ask: it reads the apt cache in place, taking no lock and touching
     * no network, unlike `apt-get -s upgrade`.
     *
     * @return array<string, int|null>
     */
    private function pending(): array
    {
        $none = ['updates_available' => null, 'security_updates_available' => null];

        $binary = (string) config('server.apt_check');

        // Ships in update-notifier-common, which a minimal install may lack.
        if (! is_file($binary)) {
            return $none;
        }

        $result = $this->serverOps->run([$binary], ['feature' => 'setting', 'group' => 'updates', 'op' => 'apt_check']);

        if ($result->failed()) {
            return $none;
        }

        if (preg_match('/^(\d+);(\d+)/', trim($result->errorOutput()), $counts) !== 1) {
            return $none;
        }

        return [
            'updates_available' => (int) $counts[1],
            'security_updates_available' => (int) $counts[2],
        ];
    }

    /**
     * When apt last *successfully* refreshed its package lists.
     *
     * APT::Periodic touches this stamp only on success, which is the question
     * being asked. The mtime of /var/lib/apt/lists moves on failed runs too, so
     * it would report a refresh that never completed.
     *
     * @return array<string, string|null>
     */
    private function lastRefresh(): array
    {
        $stamp = (string) config('server.apt_update_stamp');

        if (! is_file($stamp) || ($mtime = @filemtime($stamp)) === false) {
            return ['lists_refreshed_at' => null, 'lists_refreshed_at_human' => null];
        }

        return $this->timestamps('lists_refreshed_at', CarbonImmutable::createFromTimestamp($mtime));
    }

    /**
     * When unattended-upgrades last ran, and whether it worked.
     *
     * Read through ServerOps rather than File::get because the log's directory
     * is root:adm 0750 — the panel user cannot open it directly. Bounded to a
     * tail: this is on a settings page, and the file grows without limit.
     *
     * @return array<string, string|null>
     */
    private function lastUnattendedRun(): array
    {
        $none = [
            'unattended_last_run_at' => null,
            'unattended_last_run_at_human' => null,
            'unattended_last_result' => null,
        ];

        $log = (string) config('server.unattended_upgrades_log');

        $result = $this->serverOps->run(
            ['tail', '-n', '200', $log],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'unattended_log'],
        );

        if ($result->failed()) {
            return $none;
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        // Only the most recent run is being judged, so anything before the last
        // start marker is another run's history — an error from a fortnight ago
        // must not make today's successful run look failed.
        $start = 0;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'Starting unattended upgrades script')) {
                $start = $index;
            }
        }

        $recent = array_slice($lines, $start);

        $at = null;
        foreach ($recent as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $stamp) === 1) {
                $at = $stamp[1];
            }
        }

        if ($at === null) {
            return $none;
        }

        try {
            $ran = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $at);
        } catch (Throwable) {
            return $none;
        }

        $failed = false;
        foreach ($recent as $line) {
            if (preg_match('/\b(ERROR|Traceback)\b/', $line) === 1) {
                $failed = true;
                break;
            }
        }

        return [
            ...$this->timestamps('unattended_last_run_at', $ran),
            // A code, not a sentence: the frontend owns the wording, the same
            // way it does for runtime-install reasons.
            'unattended_last_result' => $failed ? 'failed' : 'success',
        ];
    }

    /**
     * The panel's timestamp pair — never ISO 8601.
     *
     * @return array<string, string>
     */
    private function timestamps(string $key, CarbonImmutable $at): array
    {
        return [
            $key => $at->format('d-m-Y H:i:s'),
            $key.'_human' => $at->diffForHumans(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $enabled = $data['security_updates_enabled'] ? '1' : '0';
        $autoReboot = $data['auto_reboot'] ? 'true' : 'false';
        $rebootTime = $data['reboot_time'];
        $withUsers = ($data['reboot_with_users'] ?? false) ? 'true' : 'false';

        $config = <<<CONF
        // Managed by the panel — edit via Settings, not by hand.
        APT::Periodic::Update-Package-Lists "{$enabled}";
        APT::Periodic::Unattended-Upgrade "{$enabled}";
        Unattended-Upgrade::Automatic-Reboot "{$autoReboot}";
        Unattended-Upgrade::Automatic-Reboot-Time "{$rebootTime}";
        Unattended-Upgrade::Automatic-Reboot-WithUsers "{$withUsers}";

        CONF;

        $write = $this->files->put(
            (string) config('server.unattended_upgrades_file'),
            $config,
            ['feature' => 'setting', 'group' => 'updates'],
        );

        if ($write->failed()) {
            throw new SettingOperationException($write->reference);
        }
    }

    /**
     * Parse our managed drop-in (`key "value";`) into a map.
     *
     * @return array<string, string>
     */
    private function currentConfig(): array
    {
        $path = (string) config('server.unattended_upgrades_file');

        if (! is_file($path)) {
            return [];
        }

        $config = [];
        foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
            if (preg_match('/^\s*([\w:]+)\s+"([^"]*)"\s*;/', $line, $m)) {
                $config[$m[1]] = $m[2];
            }
        }

        return $config;
    }
}
