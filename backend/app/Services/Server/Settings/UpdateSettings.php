<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Support\CommandRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automatic security updates + auto-reboot (Ubuntu unattended-upgrades),
 * managed via a panel-owned apt.conf.d drop-in (non-destructive — the distro's
 * own 50unattended-upgrades is never touched). `reboot_required` is read from
 * the kernel flag file.
 *
 * The group also reports what the toggle is *doing*: updates waiting, when apt last refreshed,
 * and whether the last unattended run worked (a quietly failing run is otherwise invisible).
 *
 * Those reads are best-effort and return `null` when they cannot be answered. `null` means
 * "nobody knows" and `0` means "nothing is waiting"; a failed check must never read as `0`.
 */
class UpdateSettings implements SettingGroup
{
    /**
     * How far back either log is read. Too small a window pushes the run's start marker out and
     * the slice becomes the end of a run presented as all of it.
     */
    private const LOG_LINES = 500;

    /**
     * Byte budget per log in the excerpt, so two of them cannot make a settings response large.
     */
    private const LOG_EXCERPT_BYTES = 8 * 1024;

    public function __construct(
        private ManagedFile $files,
        private ServerOps $serverOps,
        private SecurityUpdateTracker $runs,
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
            // Upstream defaults this to true (the box reboots under a live SSH session); we default
            // to false and make it a deliberate choice.
            'reboot_with_users' => ($config['Unattended-Upgrade::Automatic-Reboot-WithUsers'] ?? 'false') === 'true',
            'reboot_required' => is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
            ...$this->pending(),
            ...$this->lastRefresh(),
            ...$this->lastUnattendedRun(),
            // The panel's own run, carried on the first paint so a run just started shows at once.
            'security_update' => $this->runs->latest()?->toProgress($this->mayReadLog()),
        ];
    }

    /**
     * How many upgrades are waiting, and how many of those are security.
     *
     * `apt-check` prints `updates;security` to **stderr** and nothing to stdout, so this reads the
     * error stream on purpose. It takes no lock and touches no network, unlike `apt-get -s upgrade`.
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
     * APT::Periodic touches this stamp only on success; the mtime of /var/lib/apt/lists moves on
     * failed runs too.
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
     * Read through ServerOps because the log's directory is root:adm 0750. Bounded to a tail: the
     * file grows without limit.
     *
     * @return array<string, mixed>
     */
    private function lastUnattendedRun(): array
    {
        $none = [
            'unattended_last_run_at' => null,
            'unattended_last_run_at_human' => null,
            'unattended_last_result' => null,
            'unattended_last_error' => null,
            'unattended_last_log' => null,
            'unattended_last_log_truncated' => false,
            // Whether the log could be opened at all, which differs from every field being null.
            'unattended_log_readable' => true,
        ];

        $log = (string) config('server.unattended_upgrades_log');

        $result = $this->serverOps->run(
            ['tail', '-n', (string) self::LOG_LINES, $log],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'unattended_log'],
        );

        if ($result->failed()) {
            // "Nobody could look" as all-nulls is indistinguishable from "nothing has ever run", but
            // it can mean a broken panel (missing adm group, sudoers grant never synced).
            return [...$none, 'unattended_log_readable' => false];
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        // Only the most recent run is judged: anything before the last start marker is another
        // run's history and must not make today's run look failed.
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'Starting unattended upgrades script')) {
                $start = $index;
            }
        }

        // No marker in the window: the run began before the tail, so this is only its end.
        $partial = $start === null;
        $recent = array_slice($lines, $start ?? 0);

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

        // The line that decided it, not only the verdict: the commonest failure is
        // `ERROR Lock could not be acquired` (apt busy, the run will retry), which looks the same
        // as a held package or a broken post-install script without the line.
        $failure = null;
        foreach ($recent as $line) {
            if (preg_match('/\b(ERROR|Traceback)\b/', $line) === 1) {
                $failure = $this->failureDetail($line);
                break;
            }
        }

        // The run itself, not only the deciding line: why a package refused is spread over several
        // lines and the dpkg log. Only read on failure, and only for someone who can act on it:
        // a dpkg excerpt can carry conffile diffs, debconf answers and mirror URLs, so it follows
        // `setting,manage`, fail-closed like PanelUpdateResource.
        $excerpt = $failure === null || ! $this->mayReadLog()
            ? ['content' => null, 'truncated' => false]
            : $this->runExcerpt($recent, $partial);

        return [
            ...$this->timestamps('unattended_last_run_at', $ran),
            // A code, not a sentence: the frontend owns the wording.
            'unattended_last_result' => $failure === null ? 'success' : 'failed',
            // Raw evidence beside the code, deliberately untranslated: a log line is what an
            // operator searches for.
            'unattended_last_error' => $failure,
            'unattended_last_log' => $excerpt['content'],
            'unattended_last_log_truncated' => $excerpt['truncated'],
            'unattended_log_readable' => true,
        ];
    }

    /**
     * Fail closed: no actor, no log. `read()` is also reachable from the console, where there is
     * no user; a truthy default would publish the excerpt to any caller that forgets to authenticate.
     */
    private function mayReadLog(): bool
    {
        return Auth::user()?->canManage('setting') ?? false;
    }

    /**
     * The failed run, from both logs, bounded and redacted.
     *
     * Two files: unattended-upgrades' own log says that something went wrong, but a package
     * refusing to configure has its reason (the maintainer script's output) only in the dpkg log.
     *
     * Every line goes through the redactor: an apt error quotes the repository URL, and a
     * credentialled mirror puts a password in it. Bounded from the *end* of each file.
     *
     * @param  array<int, string>  $recent
     * @return array{content: string, truncated: bool}
     */
    private function runExcerpt(array $recent, bool $partial): array
    {
        $main = $this->boundedTail($recent);
        $truncated = $partial || $main['truncated'];

        $dpkg = $this->dpkgTail();

        if ($dpkg === null) {
            return ['content' => $main['content'], 'truncated' => $truncated];
        }

        return [
            // Labelled, not just concatenated: the files differ in format, and dpkg's ordinary
            // "Setting up ..." chatter would read as part of the error.
            'content' => $main['content']."\n\n--- ".$this->dpkgLogPath()." ---\n".$dpkg['content'],
            'truncated' => $truncated || $dpkg['truncated'],
        ];
    }

    /**
     * The dpkg log's most recent block, or null when it cannot be read.
     *
     * Absent is not an error: a run that failed before dpkg was reached never writes one.
     *
     * @return array{content: string, truncated: bool}|null
     */
    private function dpkgTail(): ?array
    {
        $result = $this->serverOps->run(
            ['tail', '-n', (string) self::LOG_LINES, $this->dpkgLogPath()],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'unattended_dpkg_log'],
        );

        if ($result->failed()) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        if ($lines === [] || $lines === ['']) {
            return null;
        }

        // dpkg's log is a series of `Log started:` blocks, one per invocation.
        // Anything before the last one belongs to an earlier upgrade.
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'Log started:')) {
                $start = $index;
            }
        }

        return $this->boundedTail(array_slice($lines, $start ?? 0));
    }

    private function dpkgLogPath(): string
    {
        return (string) config('server.unattended_upgrades_dpkg_log');
    }

    /**
     * Redact every line, then keep the last whole lines that fit the budget.
     *
     * Whole lines: a mid-line cut leaves a fragment that reads like a complete statement.
     *
     * @param  array<int, string>  $lines
     * @return array{content: string, truncated: bool}
     */
    private function boundedTail(array $lines): array
    {
        $kept = [];
        $bytes = 0;
        $truncated = false;

        foreach (array_reverse($lines) as $line) {
            $safe = CommandRedactor::line(rtrim($line));
            $cost = strlen($safe) + 1;

            if ($bytes + $cost > self::LOG_EXCERPT_BYTES) {
                $truncated = true;
                break;
            }

            $kept[] = $safe;
            $bytes += $cost;
        }

        return [
            'content' => implode("\n", array_reverse($kept)),
            'truncated' => $truncated,
        ];
    }

    /**
     * One log line without the timestamp and level word (the screen already shows both).
     *
     * Redacted like command lines: an apt error can quote a repository URL with credentials, and
     * anyone who can open the settings page reads this. Bounded at 300: a Traceback's first line
     * is the useful one.
     */
    private function failureDetail(string $line): string
    {
        $text = preg_replace(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2},\d+\s*(ERROR|WARNING)?\s*/',
            '',
            trim($line),
        ) ?? trim($line);

        return Str::limit(CommandRedactor::line(trim($text)), 300);
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
     * The configuration apt will actually act on.
     *
     * The panel's own file alone says "all off" on a fresh install, while Ubuntu's own
     * 20auto-upgrades may have security updates on. `apt-config dump` merges every apt.conf.d file
     * in apt's own order. Falls back to the panel's file when apt-config cannot answer.
     *
     * @return array<string, string>
     */
    private function currentConfig(): array
    {
        $dump = $this->serverOps->run(
            ['apt-config', 'dump'],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'apt_config'],
        );

        if ($dump->ok && trim($dump->output()) !== '') {
            $config = [];

            foreach (preg_split('/\r?\n/', $dump->output()) ?: [] as $line) {
                if (preg_match('/^\s*([\w:-]+)\s+"([^"]*)"\s*;/', $line, $m)) {
                    $config[$m[1]] = $m[2];
                }
            }

            return $config;
        }

        return $this->panelFileConfig();
    }

    /**
     * Parse our managed drop-in (`key "value";`) into a map.
     *
     * The hyphen in the key class is load-bearing: `\w` does not include one, and every key
     * `apply()` writes has one (`Unattended-Upgrade`). Without it nothing matches and `read()`
     * falls back to its defaults, so the toggle forgets its state.
     *
     * @return array<string, string>
     */
    private function panelFileConfig(): array
    {
        $path = (string) config('server.unattended_upgrades_file');

        if (! is_file($path)) {
            return [];
        }

        $config = [];
        foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
            if (preg_match('/^\s*([\w:-]+)\s+"([^"]*)"\s*;/', $line, $m)) {
                $config[$m[1]] = $m[2];
            }
        }

        return $config;
    }
}
