<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/** Timezone, NTP time sync, and hostname — via timedatectl / hostnamectl. */
class GeneralSettings implements SettingGroup
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
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
        return [
            'timezone' => $this->timedatectl('Timezone') ?: 'Etc/UTC',
            'ntp' => $this->timedatectl('NTP') === 'yes',
            // Whether the clock has actually reached a time server, which is a
            // different question from whether the daemon is switched on.
            // Enabled-but-not-syncing fails silently: cron fires late and every
            // log timestamp is wrong, with nothing anywhere reporting a fault.
            'clock_synchronized' => $this->timedatectl('NTPSynchronized') === 'yes',
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
            $this->ensureHostsEntry($data['hostname'], $current['hostname']);
        }

        if ((bool) $data['ntp'] !== (bool) $current['ntp']) {
            $this->run(['timedatectl', 'set-ntp', $data['ntp'] ? 'true' : 'false']);
        }
    }

    /**
     * Point 127.0.1.1 at the new hostname in /etc/hosts.
     *
     * `hostnamectl` changes the name and nothing else. If the name does not
     * resolve, every `sudo` call waits out a DNS timeout first — the familiar
     * "sudo: unable to resolve host" pause — and since it looks like a network
     * fault, that is where people go looking. Debian's convention is a
     * 127.0.1.1 line, separate from localhost, for exactly this.
     *
     * The old name's line is replaced rather than another appended: two
     * 127.0.1.1 entries is how a box ends up resolving to whichever one came
     * first, which after a couple of renames is nobody's current hostname.
     * Lines for other addresses are left alone — an operator's own entries are
     * not ours to tidy.
     */
    private function ensureHostsEntry(string $hostname, string $previous): void
    {
        $path = (string) config('server.hosts_file', '/etc/hosts');

        $read = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'setting', 'group' => 'general', 'op' => 'hosts_read'],
        );

        if ($read->failed()) {
            // Not fatal: the hostname itself did change. Better a slow `sudo`
            // than a settings screen that reports failure for a change that
            // actually happened.
            Log::channel('server-ops')->warning('could not read hosts file after a hostname change', [
                'feature' => 'setting',
                'group' => 'general',
                'reference' => $read->reference,
            ]);

            return;
        }

        // An empty read is not an empty hosts file. Every machine has at least
        // a localhost line, so nothing here should ever be believed to be the
        // whole of one — and rewriting from an empty read would replace
        // localhost, and every entry the operator put there, with our single
        // line. Refuse rather than repair.
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
