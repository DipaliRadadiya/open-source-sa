<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use Illuminate\Support\Facades\File;

/**
 * Automatic security updates + auto-reboot (Ubuntu unattended-upgrades),
 * managed via a panel-owned apt.conf.d drop-in (non-destructive — the distro's
 * own 50unattended-upgrades is never touched). `reboot_required` is read from
 * the kernel flag file.
 */
class UpdateSettings implements SettingGroup
{
    public function __construct(private ManagedFile $files) {}

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
