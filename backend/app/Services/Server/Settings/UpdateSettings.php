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
    /**
     * How far back either log is read.
     *
     * 200 was not enough to be sure of containing a whole run: the line that
     * lists the packages to upgrade is one line per run, but a box with a long
     * allowed-origins list and a few hundred held packages pushes the start
     * marker out of the window, and the slice then silently becomes the end of
     * a run presented as all of it.
     */
    private const LOG_LINES = 500;

    /**
     * Byte budget per log in the excerpt, so two of them cannot make a settings
     * response large. The same order as PanelUpdateOutput's bound, which reads
     * the same kind of thing for the same kind of screen.
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
            // Upstream defaults this to true, meaning the box restarts under
            // an administrator who is in the middle of an SSH session. We
            // default it to false and make it a deliberate choice.
            'reboot_with_users' => ($config['Unattended-Upgrade::Automatic-Reboot-WithUsers'] ?? 'false') === 'true',
            'reboot_required' => is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
            ...$this->pending(),
            ...$this->lastRefresh(),
            ...$this->lastUnattendedRun(),
            // The panel's own run, carried on the first paint rather than
            // fetched separately. A page that rendered without it would show no
            // run in progress for as long as the first poll takes, and the most
            // likely moment to open this page is just after pressing the button.
            'security_update' => $this->runs->latest()?->toProgress($this->mayReadLog()),
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
            // Whether the log could be opened at all, which is a different
            // answer from every field above being null. See below.
            'unattended_log_readable' => true,
        ];

        $log = (string) config('server.unattended_upgrades_log');

        $result = $this->serverOps->run(
            ['tail', '-n', (string) self::LOG_LINES, $log],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'unattended_log'],
        );

        if ($result->failed()) {
            // "Nobody could look" reported as all-nulls is indistinguishable
            // from "nothing has ever run", and the screen said nothing in both
            // cases. One of those is a broken panel — a missing adm group, a
            // sudoers grant that never synced — and it must not hide behind the
            // wording for a box that is merely new.
            return [...$none, 'unattended_log_readable' => false];
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        // Only the most recent run is being judged, so anything before the last
        // start marker is another run's history — an error from a fortnight ago
        // must not make today's successful run look failed.
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'Starting unattended upgrades script')) {
                $start = $index;
            }
        }

        // No marker in the window means the run began further back than the
        // tail reaches, so what follows is the end of a run and not all of it.
        // Said out loud rather than presented as the whole story.
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

        // The line that decided it, not only the verdict.
        //
        // This matched on the error and then threw it away, so the screen said
        // "The last automatic update failed" and stopped. That reads as
        // something broken and urgent, and the commonest cause is not:
        //
        //   ERROR Lock could not be acquired (another package manager running?)
        //
        // which means apt was busy and the run will retry. Without the line
        // there is no way to tell that from a held package or a broken
        // post-install script, and the only route to the answer is SSH — which
        // the person reading a settings page usually does not have.
        $failure = null;
        foreach ($recent as $line) {
            if (preg_match('/\b(ERROR|Traceback)\b/', $line) === 1) {
                $failure = $this->failureDetail($line);
                break;
            }
        }

        // The run itself, not only the line that decided it.
        //
        // One line answers "is this urgent", and for a lock collision that is
        // the whole story. It does not answer "why did this package refuse",
        // because unattended-upgrades narrates that in several lines and dpkg
        // narrates the part that matters in a different file entirely.
        //
        // Only read on failure: a successful run has nothing to explain, and
        // this is on a page that loads on every visit.
        //
        // And only for somebody who could act on it. Viewing this group needs
        // `setting`; changing it needs `setting,manage`. One redacted line is a
        // narrow disclosure, but a dpkg excerpt can carry conffile diffs,
        // debconf answers and mirror URLs — so it follows the heavier
        // permission, fail-closed the way PanelUpdateResource gates its own
        // output even on a route that is already admin-only.
        $excerpt = $failure === null || ! $this->mayReadLog()
            ? ['content' => null, 'truncated' => false]
            : $this->runExcerpt($recent, $partial);

        return [
            ...$this->timestamps('unattended_last_run_at', $ran),
            // A code, not a sentence: the frontend owns the wording, the same
            // way it does for runtime-install reasons.
            'unattended_last_result' => $failure === null ? 'success' : 'failed',
            // Raw evidence beside the code, deliberately untranslated — the
            // same split `panel:doctor` uses between a translated title and a
            // verbatim `detail`. A log line is what an operator searches for;
            // a paraphrase is not.
            'unattended_last_error' => $failure,
            'unattended_last_log' => $excerpt['content'],
            'unattended_last_log_truncated' => $excerpt['truncated'],
            'unattended_log_readable' => true,
        ];
    }

    /**
     * Fail closed: no actor, no log.
     *
     * `read()` is also reachable from the console, where there is no user at
     * all. A truthy default there would mean the first caller that forgets to
     * authenticate publishes the excerpt.
     */
    private function mayReadLog(): bool
    {
        return Auth::user()?->canManage('setting') ?? false;
    }

    /**
     * The failed run, from both logs, bounded and redacted.
     *
     * Two files because they answer different halves of the question.
     * unattended-upgrades' own log says what it decided to do and that
     * something went wrong; when the failure is a package refusing to
     * configure, the maintainer script's output — the actual reason — is only
     * in the dpkg log it writes alongside. A report built from the first file
     * alone is why "it failed" has been the end of the sentence until now.
     *
     * Every line goes through the redactor, not just the headline: an apt error
     * quotes the repository URL it could not reach, and a credentialled mirror
     * puts a password in it. Bounded from the *end* of each file, because the
     * failure is the last thing that happened.
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
            // Named rather than merely concatenated: the two files have
            // different formats and different timestamps, and a reader who
            // cannot tell where one ends will read dpkg's ordinary "Setting
            // up ..." chatter as part of the error.
            'content' => $main['content']."\n\n--- ".$this->dpkgLogPath()." ---\n".$dpkg['content'],
            'truncated' => $truncated || $dpkg['truncated'],
        ];
    }

    /**
     * The dpkg log's most recent block, or null when it cannot be read.
     *
     * Absent is not an error worth reporting on its own: a run that failed
     * before dpkg was reached never writes one, and the main log already
     * carries the reason in that case.
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
     * Whole lines: cutting mid-line leaves a fragment that reads like a
     * complete statement, which is the one thing a diagnostic must not do.
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
     * One log line, trimmed of the parts that are noise on a screen.
     *
     * The leading timestamp goes because the run's time is already reported
     * beside this, and the level word goes because the screen has already said
     * it failed. What is left is the sentence unattended-upgrades wrote.
     *
     * Redacted through the same rules command lines are: an apt error can
     * quote a repository URL with credentials in it, and this is read by
     * anyone who can open the settings page.
     *
     * Bounded at 300 because a Traceback's first line is the useful one and
     * the rest is a Python stack that means nothing here — the full text stays
     * in the log the message names.
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
     * Parse our managed drop-in (`key "value";`) into a map.
     *
     * The hyphen in the key class is load-bearing, not tidying: `\w` does not
     * include one, and every key `apply()` writes has at least one
     * (`APT::Periodic::Unattended-Upgrade`, `Unattended-Upgrade::Automatic-Reboot`).
     * Without it no line ever matched, this returned an empty map, and `read()`
     * fell through to its own defaults — so the whole group reported itself off
     * however it was actually configured, and the toggle forgot its state on
     * every reload while the file on disk was correct the entire time.
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
            if (preg_match('/^\s*([\w:-]+)\s+"([^"]*)"\s*;/', $line, $m)) {
                $config[$m[1]] = $m[2];
            }
        }

        return $config;
    }
}
