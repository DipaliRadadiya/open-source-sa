<?php

namespace App\Models;

use App\Jobs\ProvisionApplication;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One install, or one container Pull and redeploy, of an application.
 *
 * Read by Background jobs and polled by the app screen; written by {@see ApplicationRuns}.
 *
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'application_id', 'user_id', 'kind', 'status', 'failed_step', 'failed_reason',
    'reference', 'started_at', 'finished_at',
])]
class ApplicationRun extends Model
{
    public const INSTALL = 'install';

    public const CONTAINER_REDEPLOY = 'container_redeploy';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const IN_FLIGHT = [self::QUEUED, self::RUNNING];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Queued or running, and young enough that its job can still be alive.
     *
     * Only container redeploys can strand here (an install is closed by `StrandedProvisioning`).
     * A redeploy whose worker was killed never reaches `failed()`, and a row left `running`
     * would refuse every later redeploy, restart and settings save of the site.
     *
     * @param  Builder<ApplicationRun>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', self::IN_FLIGHT)
            ->where(fn (Builder $query) => $query
                ->where('kind', '!=', self::CONTAINER_REDEPLOY)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', self::RUNNING)
                    ->where('started_at', '>=', now()->subSeconds(self::runningStaleAfter())))
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', self::QUEUED)
                    ->where('created_at', '>=', now()->subSeconds(self::queuedStaleAfter()))));
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT, true) && ! $this->isStranded();
    }

    /**
     * In flight on paper, but past the point any job can still be working on it.
     *
     * Running: past its own budget plus the unique-lock grace. Queued: it waits behind the single
     * worker, at worst the longest install, so it gets that on top before it counts as lost.
     */
    public function isStranded(): bool
    {
        if ($this->kind !== self::CONTAINER_REDEPLOY || ! in_array($this->status, self::IN_FLIGHT, true)) {
            return false;
        }

        if ($this->status === self::RUNNING) {
            return ($this->started_at ?? $this->created_at)?->lt(now()->subSeconds(self::runningStaleAfter())) ?? false;
        }

        return $this->created_at?->lt(now()->subSeconds(self::queuedStaleAfter())) ?? false;
    }

    public static function runningStaleAfter(): int
    {
        return app(ProvisioningBudget::class)->forContainerRedeploy() + ProvisionApplication::UNIQUE_LOCK_GRACE;
    }

    public static function queuedStaleAfter(): int
    {
        return app(ProvisioningBudget::class)->longest() + self::runningStaleAfter();
    }
}
