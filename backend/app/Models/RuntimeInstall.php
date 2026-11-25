<?php

namespace App\Models;

use App\Enums\InstallStatus;
use App\Support\OsRelease;
use Illuminate\Database\Eloquent\Model;

class RuntimeInstall extends Model
{
    protected $fillable = [
        'runtime', 'version', 'extension', 'status', 'reason', 'reference',
        'current_step', 'output', 'started_at', 'picked_up_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallStatus::class,
            'was_absent' => 'boolean',
            'started_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The localized sentence for a failure, in the viewer's locale (hence `reason` is stored,
     * not a finished string).
     */
    public function message(): ?string
    {
        if ($this->status !== InstallStatus::Failed) {
            return null;
        }

        $message = $this->reasonMessage();

        // Add the package manager's error line when the reason is generic; a classified reason
        // already says it in words.
        $generic = in_array((string) $this->reason, ['', 'unknown', 'failed', 'remove_failed', 'remove_unknown'], true);
        $line = $this->errorLine();

        return $generic && $line !== null
            ? $message.' '.__('runtime.package_manager_said', ['line' => $line])
            : $message;
    }

    /**
     * The package manager's own error line, out of the stored output tail.
     *
     * The last `E:` (apt), `dpkg: error` or `error:` line. Null when there is none.
     */
    public function errorLine(): ?string
    {
        $found = null;

        foreach (preg_split('/\r?\n/', (string) $this->output) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^(E:|dpkg: error|error:|Error:)\s*/', $line) === 1) {
                $found = $line;
            }
        }

        return $found === null ? null : mb_strimwidth($found, 0, 300, '…');
    }

    private function reasonMessage(): string
    {
        // A removal also ends as `failed`; its reason carries a `remove_` prefix so an uninstall
        // is not reported as an install failure.
        $removing = str_starts_with((string) $this->reason, 'remove_');
        $operation = $removing
            ? 'uninstall_failed'
            : ($this->extension === '' ? 'install_failed' : 'extension_install_failed');
        $reason = $removing
            ? (substr((string) $this->reason, strlen('remove_')) ?: 'unknown')
            : ($this->reason ?: 'unknown');

        $replace = [
            'version' => $this->label(),
            'extension' => $this->extension,
            // Read live: it is a property of this server, not of the row.
            'os' => OsRelease::label() ?? __('runtime.this_server'),
        ];

        // A per-runtime group is consulted first, then the shared one: the shared
        // `install_failed` wording is written for PHP and misleads for other runtimes.
        $groups = ["{$this->runtime}_{$operation}", $operation];

        foreach ($groups as $group) {
            $key = "runtime.{$group}.{$reason}";

            if (__($key, $replace) !== $key) {
                return __($key, $replace);
            }
        }

        // Fall back so a missing translation never reaches the user as a raw key.
        foreach ($groups as $group) {
            $key = "runtime.{$group}.unknown";

            if (__($key, $replace) !== $key) {
                return __($key, $replace);
            }
        }

        return __('runtime.install_failed.unknown', $replace);
    }

    /**
     * What `:version` should read as in a sentence: the version for PHP and Node, the catalog
     * label for a database engine (the column holds the engine key, e.g. `mongodb`).
     */
    private function label(): string
    {
        if ($this->runtime !== 'database') {
            return (string) $this->version;
        }

        return (string) config("server.databases.engines.{$this->version}.label", $this->version);
    }

    /**
     * Asked for, not yet picked up by a worker.
     */
    public function isQueued(): bool
    {
        return in_array($this->status, [InstallStatus::Installing, InstallStatus::Removing], true)
            && $this->picked_up_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return [
            'status' => $this->status->value,
            'queued' => $this->isQueued(),
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'reason' => $this->reason,
            'message' => $this->message(),
            'reference' => $this->reference,
            // apt's error line on its own, for a screen that shows one line.
            'error_line' => $this->status === InstallStatus::Failed ? $this->errorLine() : null,
            // What apt is doing, read from its output (see InstallProgress); null until recognised.
            'current_step' => $this->current_step,
            // apt's own words, tail only: the step says where it stopped, this says why.
            'output' => $this->output,
        ];
    }
}
