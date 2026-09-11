<?php

namespace App\Models;

use App\Enums\InstallStatus;
use App\Support\OsRelease;
use Illuminate\Database\Eloquent\Model;

class RuntimeInstall extends Model
{
    protected $fillable = [
        'runtime', 'version', 'extension', 'status', 'reason', 'reference',
        'current_step', 'output', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallStatus::class,
            'was_absent' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The localized sentence for a failure, in the *viewer's* locale rather
     * than the locale of whoever started the install — the same rule the
     * activity log follows, and the reason `reason` is stored instead of a
     * finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== InstallStatus::Failed) {
            return null;
        }

        // A removal's terminal status is `failed`, same as an install's, so
        // its reason carries the operation prefix. Do not tell an operator an
        // uninstall was an installation failure — the remedy is different.
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
            // Named live, because the answer is a property of this server and
            // not of the row: an install that failed because a vendor has not
            // built for this release should say which release.
            'os' => OsRelease::label() ?? __('runtime.this_server'),
        ];

        /*
         * The runtime is part of the lookup, not just the row.
         *
         * This used to be the operation alone, so `install_failed` was shared
         * by PHP, Node, database engines and fail2ban alike — and it is worded
         * for PHP. A failed MongoDB install therefore told the user to "check
         * the PHP repository is configured and reachable": the wrong component,
         * pointing at the wrong remedy, on a screen that has nothing to do with
         * PHP. A per-runtime group is consulted first and the shared one
         * remains the fallback, so only the reasons that genuinely differ per
         * runtime need their own wording.
         */
        $groups = ["{$this->runtime}_{$operation}", $operation];

        foreach ($groups as $group) {
            $key = "runtime.{$group}.{$reason}";

            if (__($key, $replace) !== $key) {
                return __($key, $replace);
            }
        }

        // An unrecognised reason falls back rather than rendering the key at
        // the user: a missing translation must not become UI text.
        foreach ($groups as $group) {
            $key = "runtime.{$group}.unknown";

            if (__($key, $replace) !== $key) {
                return __($key, $replace);
            }
        }

        return __('runtime.install_failed.unknown', $replace);
    }

    /**
     * What `:version` should read as in a sentence.
     *
     * For PHP and Node the version *is* the name. For a database engine the
     * column holds the engine key (`mongodb`), and a message reading "mongodb
     * has not published packages" is the panel talking to itself — the catalog
     * already carries the label everything else displays.
     */
    private function label(): string
    {
        if ($this->runtime !== 'database') {
            return (string) $this->version;
        }

        return (string) config("server.databases.engines.{$this->version}.label", $this->version);
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return [
            'status' => $this->status->value,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'reason' => $this->reason,
            'message' => $this->message(),
            'reference' => $this->reference,
            // What apt is doing, read out of its own output rather than
            // guessed — see InstallProgress. Null until it has said something
            // recognisable, which the screen shows as "starting" rather than
            // inventing a first step.
            'current_step' => $this->current_step,
            // apt's own words, tail only. The step says where an install
            // stopped; this is the only thing that says why, and "unable to
            // locate package" and "could not get lock" are the same failed
            // install without it.
            'output' => $this->output,
        ];
    }
}
