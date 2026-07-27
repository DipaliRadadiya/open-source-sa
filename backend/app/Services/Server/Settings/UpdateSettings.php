<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use Illuminate\Support\Facades\File;

/**
 * Automatic security updates + auto-reboot (Ubuntu unattended-upgrades),
 * managed via a panel-owned apt.conf.d drop-in (non-destructive — the distro's
 * own 50unattended-upgrades is never touched). `reboot_required` is read from
 * the kernel flag file.
 */
class UpdateSettings implements SettingGroup
{
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
            'reboot_required' => is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
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

        $config = <<<CONF
        // Managed by the panel — edit via Settings, not by hand.
        APT::Periodic::Update-Package-Lists "{$enabled}";
        APT::Periodic::Unattended-Upgrade "{$enabled}";
        Unattended-Upgrade::Automatic-Reboot "{$autoReboot}";
        Unattended-Upgrade::Automatic-Reboot-Time "{$rebootTime}";

        CONF;

        File::put((string) config('server.unattended_upgrades_file'), $config);
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
