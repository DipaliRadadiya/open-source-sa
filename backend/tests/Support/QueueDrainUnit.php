<?php

namespace Tests\Support;

/** Fixture-only model of the selected systemctl show properties. */
class QueueDrainUnit
{
    public static function metadata(string $path, string $dropIns = '', string $pending = 'no', array $overrides = []): string
    {
        $unit = is_file($path) ? (string) file_get_contents($path) : '';
        $metadata = ['FragmentPath' => $path, 'DropInPaths' => $dropIns, 'NeedDaemonReload' => $pending, 'MainPID' => '0'];
        foreach (['KillMode' => 'control-group', 'KillSignal' => 'SIGTERM', 'RestartKillSignal' => 'SIGTERM', 'SendSIGHUP' => 'no', 'SendSIGKILL' => 'yes', 'FinalKillSignal' => 'SIGKILL', 'TimeoutStopFailureMode' => 'terminate'] as $key => $default) {
            preg_match('/^'.$key.'=(.*)$/m', $unit, $match);
            $metadata[$key] = match ($match[1] ?? $default) {
                'SIGTERM' => '15', 'SIGKILL' => '9', default => $match[1] ?? $default
            };
        }
        preg_match('/^TimeoutStopSec=(.*)$/m', $unit, $timeout);
        $metadata['TimeoutStopUSec'] = $timeout[1] ?? '90s';
        $metadata = array_replace($metadata, $overrides);

        return implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($metadata), $metadata))."\n";
    }
}
