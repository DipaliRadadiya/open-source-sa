<?php

namespace App\Services\Server;

use App\Exceptions\Server\Process\ProcessKillException;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Signals a running process, subject to a short list of refusals.
 *
 * An endpoint that signals arbitrary PIDs can take a server down from a web form, so the guards are
 * the substance of this class. It does not second-guess the administrator (whoever holds
 * `dashboard:manage` could run `kill` over SSH); the refusals cover clicks that almost certainly
 * do not mean what the user thinks.
 */
class ProcessKiller
{
    /**
     * TERM lets a process flush and close files; KILL does not, so it is not the default.
     *
     * @var array<int, string>
     */
    public const SIGNALS = ['TERM', 'KILL'];

    /**
     * Database server processes, as `ps` names them, and the units they run in.
     *
     * Refused outright, like SSH: one click would take every site's database offline. A hung
     * database is restarted from the Services screen; a signal from here only takes it down.
     *
     * Both lists, because each misses something: the name catches a database the unit lookup
     * cannot place, and the unit catches every PostgreSQL backend (its `comm` is its role's name).
     *
     * @var array<int, string>
     */
    public const DATABASE_COMMANDS = ['mariadbd', 'mysqld', 'mysqld_safe', 'postgres', 'mongod'];

    /** @var array<int, string> */
    public const DATABASE_UNITS = ['mariadb', 'mysql', 'mysqld', 'postgresql', 'mongod', 'mongodb'];

    /**
     * Units the operating system itself runs on.
     *
     * Stopping any of these from a web form is never the fix. cron and dbus do not come back on
     * their own (cron runs the panel's scheduler, so backups and renewals quietly stop); the rest
     * take down logging, name resolution, logins or the clock.
     *
     * @var array<int, string>
     */
    public const CORE_UNITS = [
        'dbus', 'cron', 'polkit', 'rsyslog', 'chrony',
        'systemd-journald', 'systemd-logind', 'systemd-networkd',
        'systemd-resolved', 'systemd-udevd', 'systemd-timesyncd',
    ];

    /**
     * Services that guard or run everything else.
     *
     * Killing fail2ban from the process list would bypass the Services screen's permission and it
     * stays down (`Restart=on-failure` ignores a clean TERM). Same for supervisor (every site's
     * workers) and Docker's daemons (every container). They are managed from the Services screen
     * and never killed from here.
     *
     * @var array<int, string>
     */
    public const MANAGED_UNITS = ['fail2ban', 'supervisor', 'docker', 'containerd'];

    /**
     * The largest PID Linux can hand out (`PID_MAX_LIMIT` on 64-bit); anything above it is "not
     * found" rather than an integer the rest of the stack has to survive.
     */
    public const PID_MAX = 4194304;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array{pid: int, command: string, user: string, signal: string}
     */
    public function kill(string $pid, string $signal): array
    {
        $pid = self::validPid($pid) ?? throw ProcessKillException::notFound();

        $process = $this->inspect($pid);

        if ($process === null) {
            // Not "already dead, job done": PIDs are recycled, so a stale one may be another process.
            throw ProcessKillException::notFound();
        }

        $refusal = $this->refusal($pid, $process['command'], $process['ppid'], fn (): ?string => $this->unitOf($pid));

        if ($refusal !== null) {
            throw ProcessKillException::refused($refusal);
        }

        $started = $this->startedAt($pid);

        $result = $this->serverOps->run(
            ['kill', "-{$signal}", (string) $pid],
            ['feature' => 'process', 'op' => 'kill', 'pid' => $pid, 'signal' => $signal],
        );

        if ($result->failed()) {
            // Gone between the look and the signal: the same 404 as a PID that was never there.
            if ($this->inspect($pid) === null) {
                throw ProcessKillException::notFound();
            }

            throw ProcessKillException::failed($result->reference);
        }

        if (! $this->exited($pid, $started)) {
            throw $signal === 'KILL'
                ? ProcessKillException::survivedKill()
                : ProcessKillException::stillRunning();
        }

        return [...$process, 'pid' => $pid, 'signal' => $signal];
    }

    /**
     * Why a process may not be stopped from this screen, or null when it may.
     *
     * One answer for both callers, the Stop endpoint and the process list that greys the button
     * out, so the list never offers Stop on a process the endpoint refuses.
     *
     * `$unit` is a closure for the endpoint (finding it costs a command and earlier checks usually
     * answer first); the list already has every unit from one `ps` call and passes a string.
     *
     * @param  string|null|Closure(): ?string  $unit
     * @return 'protected'|'kernel_thread'|'self'|'database'|null
     */
    public function refusal(int $pid, string $command, int $ppid, string|Closure|null $unit): ?string
    {
        // PID 1 is the init system; killing it panics the kernel.
        if ($pid === 1) {
            return 'protected';
        }

        // Kernel threads (children of kthreadd, PID 2) do not respond to signals.
        if ($pid === 2 || $ppid === 2) {
            return 'kernel_thread';
        }

        // Killing our own worker kills this request; killing the master takes the panel offline.
        if ($pid === getmypid() || $pid === posix_getppid()) {
            return 'self';
        }

        if ($this->belongsToProtectedService($command)) {
            // Already not stoppable from the Services screen; a PID is no way around that.
            return 'protected';
        }

        $unit = $unit instanceof Closure ? $unit() : $unit;

        if (in_array($command, self::DATABASE_COMMANDS, true) || $this->isDatabaseUnit($unit)) {
            return 'database';
        }

        if ($this->inProtectedUnit($unit)) {
            return 'protected';
        }

        return null;
    }

    /**
     * The PID in a request path as an integer, or null when it cannot name a process. Taken as a
     * string because the route only promises digits and a value past PHP_INT_MAX does not fit `int`.
     */
    public static function validPid(string $pid): ?int
    {
        if (preg_match('/^[0-9]{1,7}$/', $pid) !== 1) {
            return null;
        }

        $value = (int) $pid;

        return $value >= 1 && $value <= self::PID_MAX ? $value : null;
    }

    /**
     * When the process started, as `ps` prints it: what a recycled PID does not share. Null when
     * the PID is not running.
     */
    private function startedAt(int $pid): ?string
    {
        $result = $this->serverOps->run(
            ['ps', '-o', 'lstart=,stat=', '-p', (string) $pid],
            ['feature' => 'process', 'op' => 'inspect_start', 'pid' => $pid],
            expectedExitCodes: [1],
        );

        $line = trim($result->output());

        if (! $result->ok || $line === '') {
            return null;
        }

        // A zombie has exited and only awaits its parent; no signal speeds that up.
        $state = (string) preg_replace('/^.*\s/', '', $line);

        if (str_starts_with($state, 'Z')) {
            return null;
        }

        return trim((string) preg_replace('/\s+\S+$/', '', $line));
    }

    /**
     * Whether the signalled process has gone.
     *
     * `kill` returns once the signal is delivered, and TERM is a request the process may delay or
     * ignore. A PID that comes back with a different start time is another process, so the one we
     * signalled has gone.
     */
    private function exited(int $pid, ?string $started): bool
    {
        $deadline = microtime(true) + (float) config('server.metrics.stop_wait_seconds', 5);

        while (true) {
            $now = $this->startedAt($pid);

            if ($now === null || $now !== $started) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            Sleep::for(250)->milliseconds();
        }
    }

    /**
     * Live details for a PID, or null when it isn't running.
     *
     * Re-read at kill time, not trusted from the request: the process may have exited and its PID
     * been reused since the table rendered.
     *
     * @return array{command: string, user: string, ppid: int}|null
     */
    public function inspect(int $pid): ?array
    {
        $result = $this->serverOps->run(
            ['ps', '-o', 'comm=,user=,ppid=', '-p', (string) $pid],
            ['feature' => 'process', 'op' => 'inspect', 'pid' => $pid],
        );

        $line = trim($result->output());

        if (! $result->ok || $line === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $line);

        return [
            'command' => (string) ($parts[0] ?? ''),
            'user' => (string) ($parts[1] ?? ''),
            'ppid' => (int) ($parts[2] ?? 0),
        ];
    }

    /**
     * The systemd unit a PID runs in, without its `.service` suffix, or null
     * when systemd cannot say.
     */
    private function unitOf(int $pid): ?string
    {
        $result = $this->serverOps->run(
            ['ps', '-o', 'unit=', '-p', (string) $pid],
            ['feature' => 'process', 'op' => 'inspect_unit', 'pid' => $pid],
        );

        $unit = preg_replace('/\.service$/', '', trim($result->output()));

        return (! $result->ok || $unit === '' || $unit === '-') ? null : $unit;
    }

    /**
     * `postgresql@18-main` is a cluster of `postgresql`; the instance part is
     * the version and cluster name, which differ per server.
     */
    private function isDatabaseUnit(?string $unit): bool
    {
        return $unit !== null && in_array(explode('@', $unit)[0], self::DATABASE_UNITS, true);
    }

    /**
     * Whether the process runs inside a unit the server cannot do without, asked of systemd rather
     * than guessed from the process name (the name check knows only the units in config).
     *
     * Without this, the SSH daemon and the panel's queue worker could be stopped: a clean TERM is
     * not a failure systemd restarts, so SSH logins would stop on servers without socket activation.
     *
     * Protected: every unit the Services screen protects (web server, redis, the panel's PHP), SSH,
     * the panel's own units and the OS's own (CORE_UNITS). A process in a user's login session or a
     * site's app unit is not affected. When systemd cannot say, only the name check applies.
     */
    private function inProtectedUnit(?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        $protected = [
            ...app(ServiceManager::class)->protectedUnits(),
            'ssh', 'sshd',
            ...self::CORE_UNITS,
            ...self::MANAGED_UNITS,
            ...array_values((array) config('panel_update.services', [])),
        ];

        foreach ($protected as $candidate) {
            if ($unit === preg_replace('/\.service$/', '', (string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function belongsToProtectedService(string $command): bool
    {
        foreach (config('server.protected_services', []) as $unit) {
            if ($this->letters($command) === $this->letters((string) $unit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name reduced to its letters.
     *
     * The unit and its process are spelled differently (systemd `php8.4-fpm`, `ps` `php-fpm8.4`);
     * a missed match here lets the panel kill its own PHP.
     */
    private function letters(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z]/', '', $name));
    }
}
