<?php

namespace App\Services\Server;

use App\Exceptions\Server\Process\ProcessKillException;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Signals a running process, subject to a short list of refusals.
 *
 * An endpoint that signals arbitrary PIDs is a way to take a server down from
 * a web form, so the guards are the substance of this class rather than a
 * wrapper around `kill`. What it does *not* do is try to second-guess the
 * administrator: whoever holds `dashboard:manage` could run `kill` over SSH,
 * so the panel is saving them a login, not granting them a new power. The
 * refusals below are the cases where the click almost certainly does not mean
 * what the user thinks it means.
 */
class ProcessKiller
{
    /**
     * TERM asks a process to shut down and lets it flush and close files.
     * KILL gives it no such chance, which is why it is not the default —
     * a database interrupted mid-write is a worse outcome than a process
     * that takes a moment to exit.
     *
     * @var array<int, string>
     */
    public const SIGNALS = ['TERM', 'KILL'];

    /**
     * Database server processes, as `ps` names them, and the units they run in.
     *
     * Bug #5: this screen stopped MariaDB, MySQL, PostgreSQL and MongoDB with
     * one click, taking every site's database offline at once. Operator's
     * call (2026-10-03): refuse them outright, like SSH. A hung database is
     * restarted from the Services screen, which brings it back up; a signal
     * from here only takes it down.
     *
     * Both lists, because each misses something on its own: the name catches
     * a database the unit lookup cannot place, and the unit catches every
     * PostgreSQL backend, whose `comm` is whatever its role set it to.
     *
     * @var array<int, string>
     */
    public const DATABASE_COMMANDS = ['mariadbd', 'mysqld', 'mysqld_safe', 'postgres', 'mongod'];

    /** @var array<int, string> */
    public const DATABASE_UNITS = ['mariadb', 'mysql', 'mysqld', 'postgresql', 'mongod', 'mongodb'];

    /**
     * Units the operating system itself runs on (bug #5).
     *
     * Stopping any of these from a web form is never the fix for anything the
     * screen shows. cron and dbus do not come back on their own — cron is what
     * runs the panel's scheduler, so backups and renewals quietly stop — and
     * the rest take down logging, name resolution, logins or the clock. Unit
     * names measured with `ps -eo unit=` on Ubuntu 26.04 (nginx test server).
     *
     * @var array<int, string>
     */
    public const CORE_UNITS = [
        'dbus', 'cron', 'polkit', 'rsyslog', 'chrony',
        'systemd-journald', 'systemd-logind', 'systemd-networkd',
        'systemd-resolved', 'systemd-udevd', 'systemd-timesyncd',
    ];

    /**
     * The largest PID Linux can hand out (`PID_MAX_LIMIT` on 64-bit). Anything
     * above it cannot be running, so it is "not found" rather than an integer
     * the rest of the stack has to survive (bug #8).
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
            // Not "already dead, job done": PIDs are recycled, so a stale PID
            // may now belong to something else entirely.
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
     * One answer for both callers: the Stop endpoint, and the process list
     * that greys the button out (bug #7). Two copies of these rules would
     * drift, and a list that offers Stop on a process the endpoint refuses is
     * exactly the report that started this.
     *
     * `$unit` is a closure for the endpoint, where finding the unit is one
     * more command and the checks before it usually answer first; the list
     * already has every unit from one `ps` call and passes it as a string.
     *
     * @param  string|null|Closure(): ?string  $unit
     * @return 'protected'|'kernel_thread'|'self'|'database'|null
     */
    public function refusal(int $pid, string $command, int $ppid, string|Closure|null $unit): ?string
    {
        // PID 1 is the init system. Killing it panics the kernel — there is no
        // circumstance in which this is the intent.
        if ($pid === 1) {
            return 'protected';
        }

        // Kernel threads (children of kthreadd, PID 2) aren't processes in any
        // sense the user means, and don't respond to signals.
        if ($pid === 2 || $ppid === 2) {
            return 'kernel_thread';
        }

        // Killing our own worker kills the request doing the killing; killing
        // the master takes the panel offline and with it the way back in.
        if ($pid === getmypid() || $pid === posix_getppid()) {
            return 'self';
        }

        if ($this->belongsToProtectedService($command)) {
            // These already can't be stopped from the Services screen. A PID
            // is not a way around that decision.
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
     * The PID in a request path as an integer, or null when it cannot name a
     * process. Taken as a string because the route only promises digits: a
     * value past PHP_INT_MAX does not fit the `int` a controller would ask
     * for, and that mismatch was a 500.
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
     * When the process started, as `ps` prints it — the part of a process
     * that a recycled PID does not share. Null when the PID is not running.
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

        // A zombie has exited; it is only waiting for its parent to read the
        // exit status, and no signal will make it go faster.
        $state = (string) preg_replace('/^.*\s/', '', $line);

        if (str_starts_with($state, 'Z')) {
            return null;
        }

        return trim((string) preg_replace('/\s+\S+$/', '', $line));
    }

    /**
     * Whether the signalled process has gone (bug #6).
     *
     * `kill` returns as soon as the signal is delivered, and TERM is a
     * request: a process may take a moment to shut down, or ignore it. The
     * screen used to say "stopped" either way. A PID that comes back with a
     * different start time is another process that has been handed the same
     * number, so the one we signalled has gone.
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
     * Deliberately re-read at kill time rather than trusted from the request:
     * between the table rendering and the click, the process may have exited
     * and its PID been handed to something else.
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
     * Whether the process runs inside a unit the server cannot do without,
     * asked of systemd rather than guessed from the process name.
     *
     * The name check above knows two units from config. On the nginx test
     * server the Processes screen stopped the SSH daemon and the panel's own
     * queue worker with a 200 — neither name is on that list. The queue worker
     * came back (systemd restarts it) with whatever job it was running lost;
     * sshd came back only because 26.04 socket-activates it. On a server that
     * does not, a clean TERM is not a failure systemd restarts, and new SSH
     * logins stop until someone restarts it by other means.
     *
     * Protected: every unit the Services screen protects (web server, redis,
     * the panel's PHP), SSH, the panel's own units, and the operating system's
     * own (CORE_UNITS). A process in a user's
     * login session or a site's app unit is not affected. When systemd cannot
     * say, the name check above is all there is — as before.
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
     * The unit and the process it runs are not spelled the same: systemd
     * calls it `php8.4-fpm` while `ps` reports `php-fpm8.4`. Comparing the
     * strings, or their prefixes, misses that — and a missed match here means
     * the panel lets you kill its own PHP.
     */
    private function letters(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z]/', '', $name));
    }
}
