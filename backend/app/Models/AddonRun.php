<?php

namespace App\Models;

use App\Jobs\RunAddonCommand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued addon command and, once it finished, what the addon answered.
 * {@see RunAddonCommand}
 */
class AddonRun extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /**
     * Commands that install or remove the addon itself rather than run it.
     * Their runs have no application, and only one may be under way per addon.
     */
    public const INSTALL = 'install';

    public const REMOVE = 'remove';

    protected $fillable = ['application_id', 'addon', 'command', 'status', 'arguments', 'input', 'http_status', 'result', 'started_at', 'finished_at'];

    /** Never serialised: it can hold a script or credentials. */
    protected $hidden = ['input'];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            // What the command reads on stdin; encrypted at rest.
            'input' => 'encrypted',
            'result' => 'array',
            'http_status' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The install or remove of this addon that is queued or running, if any.
     *
     * One left behind by a worker that died with it would hold every later
     * one off for good, so a run older than `lifecycle_stranded_seconds` is
     * closed as failed here instead of counted.
     */
    public static function lifecycleInProgress(string $addon): ?self
    {
        $active = static::query()
            ->where('addon', $addon)
            ->whereIn('command', [self::INSTALL, self::REMOVE])
            ->whereIn('status', [self::QUEUED, self::RUNNING]);

        // One by one rather than one UPDATE: `result` goes through its cast.
        (clone $active)
            ->where('created_at', '<', now()->subSeconds((int) config('server.addons.lifecycle_stranded_seconds')))
            ->get()
            ->each(fn (self $run) => $run->update([
                'status' => self::FAILED,
                'http_status' => 504,
                'result' => ['code' => 'addon_timed_out', 'message' => __('errors/addons.stranded')],
                'input' => null,
                'finished_at' => now(),
            ]));

        return $active->latest()->first();
    }
}
