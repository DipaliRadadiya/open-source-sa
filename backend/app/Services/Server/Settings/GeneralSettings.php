<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\LegacyPhpTimezone;
use App\Services\Server\ServerOps;
use App\Support\ServerTimezone;
use Illuminate\Support\Facades\Log;

/** Timezone, NTP time sync, and hostname — via timedatectl / hostnamectl. */
class GeneralSettings implements SettingGroup
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private LegacyPhpTimezone $legacyPhp,
    ) {}

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
        // When timedatectl does not answer, the clock fields are null ("unknown"), not defaults.
        $timezone = $this->timedatectl('Timezone');
        $known = $timezone !== '';

        return [
            'timezone' => $known ? $timezone : null,
            'ntp' => $known ? $this->timedatectl('NTP') === 'yes' : null,
            // Whether the clock has reached a time server, not just whether the daemon is on.
            'clock_synchronized' => $known ? $this->timedatectl('NTPSynchronized') === 'yes' : null,
            'hostname' => $this->hostname(),
        ];
    }

    /**
     * Apply only what actually changed.
     *
     * The form submits all three fields every time, and they need different privileges
     * (`hostnamectl set-hostname` can be refused by polkit): running them all could fail on an
     * untouched field after another change was already written.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $current = $this->read();

        if ($data['timezone'] !== $current['timezone']) {
            $this->run(['timedatectl', 'set-timezone', $data['timezone']]);
            ServerTimezone::forget();

            // PHP 5.6–7.4 cannot guess any zone but UTC; their sites would answer 500.
            $this->legacyPhp->applyAll($data['timezone']);
        }

        if ($data['hostname'] !== $current['hostname']) {
            $this->run(['hostnamectl', 'set-hostname', $data['hostname']]);
            $this->ensureHostsEntry($data['hostname'], $current['hostname']);
        }

        if ((bool) $data['ntp'] !== (bool) $current['ntp']) {
            $this->run(['timedatectl', 'set-ntp', $data['ntp'] ? 'true' : 'false']);
        }
    }

    /**
     * Point 127.0.1.1 at the new hostname in /etc/hosts.
     *
     * `hostnamectl` does not touch /etc/hosts; an unresolvable hostname makes every `sudo` wait
     * out a DNS timeout. Debian's convention is a separate 127.0.1.1 line.
     *
     * The old 127.0.1.1 line is replaced, not appended to; other addresses are left alone.
     */
    private function ensureHostsEntry(string $hostname, string $previous): void
    {
        $path = (string) config('server.hosts_file', '/etc/hosts');

        $read = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'setting', 'group' => 'general', 'op' => 'hosts_read'],
        );

        if ($read->failed()) {
            // Not fatal: the hostname itself did change.
            Log::channel('server-ops')->warning('could not read hosts file after a hostname change', [
                'feature' => 'setting',
                'group' => 'general',
                'reference' => $read->reference,
            ]);

            return;
        }

        // An empty read is not an empty hosts file: rewriting from it would wipe localhost and
        // the operator's entries. Refuse rather than repair.
        if (trim($read->output()) === '') {
            Log::channel('server-ops')->warning('hosts file read came back empty; leaving it untouched', [
                'feature' => 'setting',
                'group' => 'general',
                'path' => $path,
                'reference' => $read->reference,
            ]);

            return;
        }

        $lines = preg_split('/\r?\n/', rtrim($read->output(), "\n")) ?: [];

        $kept = array_values(array_filter(
            $lines,
            fn (string $line): bool => ! preg_match('/^\s*127\.0\.1\.1\s/', $line),
        ));

        $kept[] = "127.0.1.1\t{$hostname}";

        $write = $this->files->put(
            $path,
            implode("\n", $kept)."\n",
            ['feature' => 'setting', 'group' => 'general', 'op' => 'hosts_write'],
        );

        if ($write->failed()) {
            Log::channel('server-ops')->warning('could not write hosts file after a hostname change', [
                'feature' => 'setting',
                'group' => 'general',
                'hostname' => $hostname,
                'previous' => $previous,
                'reference' => $write->reference,
            ]);
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
