<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\InstallStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\ServerOperationException;
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
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            // A working queue worker should be one click, not a remembered set of flags.
            'presets' => $presets->for($application),
            'checks' => $presets->checks($application),
        ]);
    }

    /**
     * Install supervisord, which workers run under.
     *
     * Dispatched, never inline: apt can wait up to ten minutes on the dpkg lock, and a request
     * held that long dies at the web server. See {@see InstallFail2ban}.
     *
     * The tracker row is started here, not in the job, so the install is visible between the
     * 202 and a worker picking the job up.
     */
    public function installSupervisor(
        Application $application,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
        InstallTracker $installs,
    ): JsonResponse {
        // Already installed: say so rather than spend minutes of apt proving it.
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
        // Checked before the row exists: otherwise a failed request leaves behind a worker the
        // panel lists and supervisord has never heard of. When supervisord is missing, install it
        // (a job, so this answers 202) and create the worker on the next attempt.
        if (! $supervisor->installed()) {
            return $this->installSupervisor($application, $supervisor, $activity, app(InstallTracker::class));
        }

        // The slug naming this worker's unit is derived on the model's `creating` hook and is not
        // fillable, so no request can choose the name of a file the panel writes.
        $data = $request->validated();

        // A queue worker not given time to finish loses its running job at every restart.
        // A custom worker keeps the column default unless asked otherwise.
        if (! array_key_exists('stop_wait_seconds', $data)
            && in_array($data['kind'] ?? Worker::KIND_CUSTOM, [Worker::KIND_QUEUE, Worker::KIND_HORIZON], true)) {
            $data['stop_wait_seconds'] = Worker::MAX_STOP_WAIT;
        }

        $worker = Worker::create($data + [
            'application_id' => $application->id,
        ]);

        // Columns the request omitted (enabled, processes, restart flags) exist only as database
        // defaults; without a refresh `$worker->enabled` is null and reads as "disabled".
        $worker->refresh();

        // Written and started right away. If that fails the row goes with it, so a failed
        // request does not leave a worker supervisord has never heard of. The exception is
        // rethrown untouched: the catch undoes, it does not hide.
        try {
            $supervisor->applySaved($worker->load('application.systemUser'));
        } catch (Throwable $e) {
            // applySaved clears `program` only after verified cleanup. If it keeps one, cleanup
            // was refused or uncertain: keep the row so a retry cannot create an untracked
            // duplicate. Do not repeat a best-effort removal here.
            if ($worker->program === null) {
                $worker->delete();
            } else {
                Log::warning('failed worker create retains its tracked program pending recovery', [
                    'feature' => 'application',
                    'op' => 'worker_create_cleanup',
                    'worker' => $worker->id,
                    'application' => $application->id,
                    'exception' => $e::class,
                ]);
            }

            throw $this->explainStart($e, $worker, $supervisor);
        }

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

        $original = clone $worker->load('application.systemUser');
        $before = $worker->only(array_keys($request->validated()));

        $worker->update($request->validated());

        // A change that will not start would leave the worker removed from supervisord with the
        // bad command saved. Put the row back and bring the old program up again; the error
        // still reaches the user unchanged.
        try {
            $supervisor->applySaved($worker->load('application.systemUser'), $original);
        } catch (Throwable $e) {
            $worker->forceFill($before)->save();

            if ($worker->hasProgram() && ! ($e instanceof ServerOperationException && $e->denied)) {
                try {
                    $supervisor->apply($worker->refresh()->load('application.systemUser'));
                } catch (Throwable $restoreException) {
                    Log::warning('restoring a worker after a failed edit also failed', [
                        'feature' => 'application',
                        'op' => 'worker_update_restore',
                        'worker' => $worker->id,
                        'application' => $application->id,
                        'exception' => $restoreException::class,
                    ]);
                }
            }

            throw $this->explainStart($e, $worker, $supervisor, edit: true);
        }

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

        // Units first: deleting the row first would leave untracked processes consuming the queue.
        $supervisor->remove($worker->load('application.systemUser'));

        $activity->log('application.worker_deleted', $application, [
            'name' => $application->name,
            'worker' => $worker->name,
        ]);

        $worker->delete();

        return response()->json(null, 204);
    }

    /**
     * A program that would not start is the user's command, not a server fault: answer 422 with
     * what the program last printed instead of a bare 500.
     */
    private function explainStart(Throwable $e, Worker $worker, WorkerSupervisor $supervisor, bool $edit = false): Throwable
    {
        if (! $e instanceof ProvisioningFailedException || $e->step !== 'start_worker') {
            return $e;
        }

        try {
            $said = $supervisor->lastOutput($worker);
        } catch (Throwable) {
            $said = null;
        }

        return new HttpResponseException(response()->json([
            // An edit is never "not added": the change was rolled back.
            'message' => $said === null
                ? __($edit ? 'worker.errors.edit_did_not_start' : 'worker.errors.did_not_start', ['reference' => $e->reference])
                : __($edit ? 'worker.errors.edit_did_not_start_said' : 'worker.errors.did_not_start_said', ['output' => $said, 'reference' => $e->reference]),
            'reason' => 'worker_did_not_start',
            'output' => $said,
            'reference' => $e->reference,
            'errors' => ['command' => [__('worker.errors.did_not_start_field')]],
        ], 422));
    }

    /**
     * The worker in the URL must be this application's.
     *
     * Route model binding resolves `{worker}` from the whole table, so without this
     * `PUT /applications/1/workers/99` edits site 2's worker while the activity log names site 1.
     *
     * 404 rather than 403, like the other nested routes: 403 would confirm the worker exists
     * under another application.
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
