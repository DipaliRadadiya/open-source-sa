<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\InstallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SaveWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Jobs\InstallFail2ban;
use App\Jobs\InstallSupervisor;
use App\Models\Application;
use App\Models\Worker;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Applications\WorkerPresets;
use App\Services\Server\Applications\WorkerSupervisor;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * An application's background workers — queue workers, Horizon, or any command
 * the site needs kept running.
 *
 * State is never stored: every response asks systemd what is actually running.
 */
class WorkerController extends Controller
{
    public function index(Application $application, WorkerPresets $presets): JsonResponse
    {
        $workers = ListSort::caseInsensitive(
            Worker::query()
                ->with('application.systemUser')
                ->where('application_id', $application->id),
            'name',
        )->get();

        return response()->json([
            'workers' => WorkerResource::collection($workers)->resolve(),
            // The empty state is the feature for most people: a working queue
            // worker should be one click, not a remembered set of flags.
            'presets' => $presets->for($application),
            'checks' => $presets->checks($application),
        ]);
    }

    /**
     * Install supervisord, which workers run under.
     *
     * Dispatched, never inline: apt waits out the dpkg lock for up to ten
     * minutes and this codebase is explicit that a package install belongs in
     * a job and never in a request — see {@see InstallFail2ban}
     * and PhpExtensionManager::install(). A request held open that long dies
     * at the web server long before apt finishes, leaving a half-done install
     * nobody can see.
     *
     * The tracker row is started here rather than inside the job, for the
     * reason the fail2ban endpoint documents: between this 202 and a worker
     * picking the job up there would otherwise be a window where the install
     * is real and nothing can see it.
     */
    public function installSupervisor(
        Application $application,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
        InstallTracker $installs,
    ): JsonResponse {
        // Already there: say so rather than spending minutes of apt proving it.
        if ($supervisor->installed()) {
            return response()->json([
                'message' => __('errors/application.supervisor_already_installed'),
            ], 422);
        }

        // Already running: a second click must not queue a second apt.
        if ($installs->current(InstallSupervisor::RUNTIME, InstallSupervisor::VERSION)?->status === InstallStatus::Installing) {
            return response()->json([
                'message' => __('application.supervisor_installing'),
            ], 202);
        }

        $installs->start(InstallSupervisor::RUNTIME, InstallSupervisor::VERSION);

        InstallSupervisor::dispatch(Auth::id());

        $activity->log('application.supervisor_install_started', $application, [
            'name' => $application->name,
        ]);

        return response()->json([
            'message' => __('application.supervisor_installing'),
        ], 202);
    }

    public function store(
        SaveWorkerRequest $request,
        Application $application,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
        // Before the row exists, not after. `apply()` checks too, but by then
        // this method has already written a worker the panel would list and
        // supervisord has never heard of — and the request that created it
        // returns an error, so nobody expects it to be there.
        //
        // And when it is missing, start installing it rather than handing back
        // a command to run. The panel has the grant and knows the package; it
        // installs PHP versions and fail2ban the same way. The install still
        // cannot happen inside this request — apt is minutes long — so this
        // answers 202 and the worker is created on the next attempt, which is
        // one more click and no shell.
        if (! $supervisor->installed()) {
            return $this->installSupervisor($application, $supervisor, $activity, app(InstallTracker::class));
        }

        // The slug that names this worker's systemd unit is derived on the
        // model's `creating` hook — it is not fillable, so no request can
        // choose the name of a file the panel writes.
        $worker = Worker::create($request->validated() + [
            'application_id' => $application->id,
        ]);

        // Refreshed before anything reads it. Columns the request omitted —
        // enabled, processes, the restart flags — exist only as database
        // defaults, and a model built by create() has no idea what those are:
        // `$worker->enabled` would be null, which reads as "disabled" and
        // stops the worker this call was supposed to start.
        $worker->refresh();

        // Written and started before the row is worth anything: a worker the
        // panel lists but never started is the kind of thing discovered when
        // the queue is already hours behind.
        $supervisor->apply($worker->load('application.systemUser'));

        $activity->log('application.worker_created', $application, [
            'name' => $application->name,
            'worker' => $worker->name,
        ]);

        return response()->json([
            'worker' => WorkerResource::make($worker)->resolve(),
        ], 201);
    }

    public function update(
        SaveWorkerRequest $request,
        Application $application,
        Worker $worker,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
        $this->assertBelongsTo($worker, $application);

        $worker->update($request->validated());

        $supervisor->apply($worker->load('application.systemUser'));

        $activity->log('application.worker_updated', $application, [
            'name' => $application->name,
            'worker' => $worker->name,
        ]);

        return response()->json([
            'worker' => WorkerResource::make($worker->fresh())->resolve(),
        ]);
    }

    public function destroy(
        Application $application,
        Worker $worker,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
        $this->assertBelongsTo($worker, $application);

        // Units removed before the row: deleting the row first would leave
        // processes running that nothing in the panel knows about, still
        // consuming the queue.
        $supervisor->remove($worker->load('application.systemUser'));

        $activity->log('application.worker_deleted', $application, [
            'name' => $application->name,
            'worker' => $worker->name,
        ]);

        $worker->delete();

        return response()->json(null, 204);
    }

    /**
     * The worker in the URL must be this application's.
     *
     * Route model binding resolves `{worker}` from the whole table, so without
     * this `PUT /applications/1/workers/99` edits site 2's worker while the
     * activity log records the action against site 1 — an audit trail naming
     * the wrong site is worse than none, because it is believed.
     *
     * 404 rather than 403, like the deployment, domain and SSH-key routes that
     * already check this: from where the caller stands that worker does not
     * exist under that application, and saying "forbidden" would confirm it
     * exists somewhere else.
     */
    private function assertBelongsTo(Worker $worker, Application $application): void
    {
        abort_unless($worker->application_id === $application->id, 404);
    }

    /** start | stop | restart. */
    public function control(
        Request $request,
        Application $application,
        Worker $worker,
        string $action,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
        $this->assertBelongsTo($worker, $application);

        abort_unless(in_array($action, ['start', 'stop', 'restart'], true), 404);
        abort_unless($request->user()?->canManage('app_worker') ?? false, 403);

        $worker->load('application.systemUser');

        match ($action) {
            'start' => $supervisor->start($worker),
            'stop' => $supervisor->stop($worker),
            'restart' => $supervisor->restart($worker),
        };

        $activity->log('application.worker_'.$action, $application, [
            'name' => $application->name,
            'worker' => $worker->name,
        ]);

        return response()->json([
            'worker' => WorkerResource::make($worker->fresh())->resolve(),
        ]);
    }
}
