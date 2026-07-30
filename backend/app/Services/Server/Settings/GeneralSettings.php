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
     * Apply only what actually changed.
     *
     * The form submits all three fields every time, so a naive apply runs
     * three privileged commands to change one value. That matters because
     * they do not all need the same privileges: `timedatectl set-timezone`
     * succeeds here as the web user while `hostnamectl set-hostname` is
     * refused by polkit with "Interactive authentication required". Applying
     * unconditionally meant changing the timezone failed on the hostname
     * call — a field the user had not touched — and worse, failed *after*
     * the timezone had already been written, so the request reported failure
     * on a change that had happened.
     *
     * Comparing against the live values first is the same rule the firewall
     * rule editor already follows: do not ask the OS to do something it is
     * already doing.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $current = $this->read();

        if ($data['timezone'] !== $current['timezone']) {
            $this->run(['timedatectl', 'set-timezone', $data['timezone']]);
        }

        if ($data['hostname'] !== $current['hostname']) {
            $this->run(['hostnamectl', 'set-hostname', $data['hostname']]);
        }

        if ((bool) $data['ntp'] !== (bool) $current['ntp']) {
            $this->run(['timedatectl', 'set-ntp', $data['ntp'] ? 'true' : 'false']);
        }
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
