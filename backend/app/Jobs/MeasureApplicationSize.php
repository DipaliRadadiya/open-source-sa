<?php

namespace App\Jobs;

use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Application;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-measure a site after something changed its contents.
 *
 * Queued and delayed rather than done inline, because `du` walks every inode:
 * on a WordPress install that is forty thousand of them, and doing it in the
 * request would make deleting one file as slow as counting the whole site.
 *
 * Unique per application *and* delayed, which together are the debounce.
 * Deleting fifty files raises fifty of these; the first one takes the lock and
 * the other forty-nine are dropped, so the site is walked once, shortly after
 * the person stops working, rather than once per click.
 *
 * The delay is the point, not an accident of scheduling — running immediately
 * would measure a directory somebody is still in the middle of changing.
 */
class MeasureApplicationSize implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    /** Long enough to cover a burst of edits, short enough to feel current. */
    public const DEBOUNCE_SECONDS = 60;

    public int $tries = 1;

    /**
     * A site large enough to take longer than this to count is one where the
     * number matters least and the disk cost matters most.
     */
    public int $timeout = 300;

    public function __construct(public int $applicationId) {}

    public function uniqueId(): string
    {
        return 'application-size-'.$this->applicationId;
    }

    public function handle(FileBrowser $files): void
    {
        $application = Application::find($this->applicationId);

        if ($application === null) {
            return;
        }

        try {
            $files->applicationSize($application, refresh: true);
        } catch (Throwable $e) {
            // A size that could not be measured is not worth failing a job
            // over, and never worth interrupting whatever the user is doing.
            // The previous figure and its date stay as they were, which is
            // honest: it says when it was last actually true.
            Log::channel('server-ops')->warning('could not measure application size', [
                'feature' => 'application',
                'op' => 'measure_size',
                'application' => $this->applicationId,
                'detail' => $e->getMessage(),
            ]);
        }
    }
}
