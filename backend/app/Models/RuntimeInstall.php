<?php

namespace App\Models;

use App\Enums\InstallStatus;
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

        // Separate wording for the two, because "PHP 8.3 could not be found"
        // and "the redis package could not be found" are different sentences
        // and a shared one would be vague enough to be useless.
        $group = $this->extension === '' ? 'install_failed' : 'extension_install_failed';
        $replace = ['version' => $this->version, 'extension' => $this->extension];

        $key = "runtime.{$group}.".($this->reason ?: 'unknown');

        // An unrecognised reason falls back rather than rendering the key at
        // the user: a missing translation must not become UI text.
        return __($key, $replace) === $key
            ? __("runtime.{$group}.unknown", $replace)
            : __($key, $replace);
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
