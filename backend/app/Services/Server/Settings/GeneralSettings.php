<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ServerOps;

/** Timezone, NTP time sync, and hostname — via timedatectl / hostnamectl. */
class GeneralSettings implements SettingGroup
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'general';
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
        return [
            'timezone' => $this->timedatectl('Timezone') ?: 'Etc/UTC',
            'ntp' => $this->timedatectl('NTP') === 'yes',
            'hostname' => $this->hostname(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $this->run(['timedatectl', 'set-timezone', $data['timezone']]);
        $this->run(['hostnamectl', 'set-hostname', $data['hostname']]);
        $this->run(['timedatectl', 'set-ntp', $data['ntp'] ? 'true' : 'false']);
    }

    private function timedatectl(string $property): string
    {
        return trim($this->serverOps->run(
            ['timedatectl', 'show', '--property='.$property, '--value'],
            ['feature' => 'setting', 'group' => 'general', 'op' => 'read'],
        )->output());
    }

    private function hostname(): string
    {
        $name = trim($this->serverOps->run(
            ['hostnamectl', '--static'],
            ['feature' => 'setting', 'group' => 'general', 'op' => 'read'],
        )->output());

        return $name !== '' ? $name : (string) gethostname();
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): void
    {
        $result = $this->serverOps->run($command, ['feature' => 'setting', 'group' => 'general', 'op' => 'apply']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
