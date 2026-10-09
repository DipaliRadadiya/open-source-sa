<?php

namespace App\Services\Panel;

use RuntimeException;

/**
 * The supported, direct-PHP worker's stop policy. This is not a systemd parser:
 * ambiguous/custom lifecycle arrangements are refused, never guessed at.
 *
 * mixed sends TERM only to the signal-aware main worker. Laravel finishes its
 * synchronous job, then exits before reserving another. systemd still kills
 * every remaining cgroup process at main exit or the existing stop deadline.
 */
class QueueWorkerUnit
{
    public const SIGNAL_CHECK = 'exit(extension_loaded("pcntl") && function_exists("pcntl_async_signals") && function_exists("pcntl_signal") && function_exists("pcntl_alarm") ? 0 : 1);';

    private const POLICY = [
        'KillMode' => 'mixed',
        'KillSignal' => 'SIGTERM',
        'RestartKillSignal' => 'SIGTERM',
        'SendSIGHUP' => 'no',
        'SendSIGKILL' => 'yes',
        'FinalKillSignal' => 'SIGKILL',
        'TimeoutStopFailureMode' => 'kill',
    ];

    /** A fail-closed check using the worker's actual PHP, including its ini. */
    public static function signalCheck(string $php): string
    {
        return "ExecStartPre={$php} -r '".self::SIGNAL_CHECK."'";
    }

    public static function reconcile(string $unit): string
    {
        $entries = self::entries($unit);
        $starts = $entries['ExecStart'] ?? [];

        if (count($starts) !== 1
            // Do not guess at systemd dequoting, escapes, environment or
            // specifier expansion: any of them can hide a --once flag.
            || strpbrk($starts[0]['value'], "\"'\\\\$%") !== false
            || preg_match('#^(/[A-Za-z0-9._/-]+/php[0-9.]*)\s+(?:/[A-Za-z0-9._/-]+/)?artisan\s+queue:work(?:\s|$)#D', $starts[0]['value'], $match) !== 1
            || preg_match('/(?:^|\s)--once(?:\s|=|$)/', $starts[0]['value']) === 1) {
            throw new RuntimeException('queue_unit_direct_php_daemon_required');
        }

        $php = $match[1];
        self::allow($entries, 'Type', ['simple', 'exec']);
        self::allow($entries, 'ExitType', ['main']);
        self::allow($entries, 'KillMode', ['control-group', 'mixed']);
        self::allow($entries, 'KillSignal', ['SIGTERM', 'TERM', '15']);
        self::allow($entries, 'RestartKillSignal', ['SIGTERM', 'TERM', '15']);
        self::allow($entries, 'FinalKillSignal', ['SIGKILL', 'KILL', '9']);
        self::allow($entries, 'SendSIGHUP', ['no', 'false', '0']);
        self::allow($entries, 'SendSIGKILL', ['yes', 'true', '1']);
        self::allow($entries, 'TimeoutStopFailureMode', ['terminate', 'kill']);
        self::allow($entries, 'Delegate', ['no', 'false', '0']);

        // TimeoutSec also sets the stop deadline, in ordering-dependent ways.
        // A watchdog/custom stop can bypass graceful worker handling entirely.
        foreach (['ExecStop', 'ExecStopPost', 'PIDFile', 'GuessMainPID', 'TimeoutSec', 'WatchdogSec', 'WatchdogSignal'] as $key) {
            if (isset($entries[$key])) {
                throw new RuntimeException('queue_unit_custom_'.$key);
            }
        }

        $timeouts = $entries['TimeoutStopSec'] ?? [];
        if (count($timeouts) > 1 || (isset($timeouts[0])
            && (preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:us|ms|s|min|h|d|w)?$/D', $timeouts[0]['value'], $timeout) !== 1
                || (float) $timeout[1] <= 0 || ! is_finite((float) $timeout[1])))) {
            throw new RuntimeException('queue_unit_finite_stop_deadline_required');
        }

        $check = self::signalCheck($php);
        $hasCheck = false;
        foreach ($entries['ExecStartPre'] ?? [] as $entry) {
            // A reset can silently remove an earlier check; refuse instead of
            // treating a textual occurrence as an effective capability gate.
            if ($entry['value'] === '') {
                throw new RuntimeException('queue_unit_reset_ExecStartPre');
            }
            $hasCheck = $hasCheck || 'ExecStartPre='.$entry['value'] === $check;
        }

        $lines = explode("\n", $unit);
        $append = [];
        foreach (self::POLICY as $key => $value) {
            if (isset($entries[$key][0])) {
                $entry = $entries[$key][0];
                // Keep comments/spacing unchanged when already canonical.
                if ($entry['value'] !== $value) {
                    $lines[$entry['line']] = $key.'='.$value;
                }
            } else {
                $append[] = $key.'='.$value;
            }
        }
        if ($timeouts === []) {
            $append[] = 'TimeoutStopSec=1800';
        }
        if (! $hasCheck) {
            $append[] = $check;
        }
        if ($append !== []) {
            $section = '';
            $insert = count($lines);
            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*\[([^\]]+)\]\s*$/D', $line, $match) === 1) {
                    if ($section === 'Service') {
                        $insert = $index;
                        break;
                    }
                    $section = $match[1];
                }
            }
            // Keep the final newline; append before the trailing empty line.
            if ($insert === count($lines) && end($lines) === '') {
                $insert--;
            }
            array_splice($lines, $insert, 0, $append);
        }

        return implode("\n", $lines);
    }

    /** Unrelated operator drop-ins remain untouched; lifecycle overrides need manual review. */
    public static function assertSafeOverrides(string $unit): void
    {
        $entries = self::entries($unit, requireService: false);
        $sensitive = array_merge(array_keys(self::POLICY), [
            'Type', 'ExitType', 'ExecStart', 'ExecStartPre', 'ExecStop', 'ExecStopPost',
            'TimeoutStopSec', 'TimeoutSec', 'WatchdogSec', 'WatchdogSignal', 'PIDFile', 'GuessMainPID', 'Delegate',
        ]);
        foreach ($sensitive as $key) {
            if (isset($entries[$key])) {
                throw new RuntimeException('queue_unit_overridden_'.$key);
            }
        }
    }

    /** @param array<string, string> $metadata */
    public static function verifyEffective(array $metadata, string $unit): void
    {
        foreach (self::POLICY as $key => $value) {
            $expected = match ($value) {
                'SIGTERM' => '15', 'SIGKILL' => '9', default => $value
            };
            if (($metadata[$key] ?? null) !== $expected) {
                throw new RuntimeException('queue_unit_effective_'.$key);
            }
        }
        $entries = self::entries($unit);
        $expected = self::seconds($entries['TimeoutStopSec'][0]['value'] ?? '');
        $effective = self::seconds($metadata['TimeoutStopUSec'] ?? '');
        if (abs($expected - $effective) > 0.000001) {
            throw new RuntimeException('queue_unit_effective_stop_deadline');
        }
    }

    /** systemctl formats durations as e.g. 30min or 1min 30s. */
    private static function seconds(string $value): float
    {
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?(?:us|ms|s|min|h|d|w)?(?:\s+[0-9]+(?:\.[0-9]+)?(?:us|ms|s|min|h|d|w)?)*$/D', $value) !== 1) {
            throw new RuntimeException('queue_unit_effective_deadline_unreadable');
        }
        preg_match_all('/([0-9]+(?:\.[0-9]+)?)(us|ms|s|min|h|d|w)?/', $value, $parts, PREG_SET_ORDER);
        $seconds = 0.0;
        foreach ($parts as $part) {
            $seconds += (float) $part[1] * match ($part[2] ?? '') {
                'us' => 0.000001, 'ms' => 0.001, 'min' => 60, 'h' => 3600,
                'd' => 86400, 'w' => 604800, default => 1,
            };
        }
        if ($seconds <= 0 || ! is_finite($seconds)) {
            throw new RuntimeException('queue_unit_effective_finite_deadline_required');
        }

        return $seconds;
    }

    /** @param array<string, list<array{value: string, line: int}>> $entries */
    private static function allow(array $entries, string $key, array $allowed): void
    {
        $values = $entries[$key] ?? [];
        if (count($values) > 1 || (isset($values[0]) && ! in_array($values[0]['value'], $allowed, true))) {
            throw new RuntimeException('queue_unit_unsupported_'.$key);
        }
    }

    /** @return array<string, list<array{value: string, line: int}>> */
    private static function entries(string $unit, bool $requireService = true): array
    {
        $section = '';
        $services = 0;
        $entries = [];
        foreach (explode("\n", $unit) as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (str_ends_with($line, '\\')) {
                throw new RuntimeException('queue_unit_continuation_unsupported');
            }
            if (preg_match('/^\[([^\]]+)\]$/D', $line, $match) === 1) {
                $section = $match[1];
                $services += $section === 'Service' ? 1 : 0;

                continue;
            }
            if ($section === 'Service') {
                if (preg_match('/^([A-Za-z][A-Za-z0-9]*)\s*=\s*(.*)$/D', $line, $match) !== 1) {
                    throw new RuntimeException('queue_unit_invalid_directive');
                }
                $entries[$match[1]][] = ['value' => trim($match[2]), 'line' => $index];
            }
        }
        if ($services > 1 || ($requireService && $services !== 1)) {
            throw new RuntimeException('queue_unit_Service_section_required');
        }

        return $entries;
    }
}
