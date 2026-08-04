<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SaveWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Application;
use App\Models\Worker;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\WorkerPresets;
use App\Services\Server\Applications\WorkerSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $workers = Worker::query()
            ->with('application.systemUser')
            ->where('application_id', $application->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'workers' => WorkerResource::collection($workers)->resolve(),
            // The empty state is the feature for most people: a working queue
            // worker should be one click, not a remembered set of flags.
            'presets' => $presets->for($application),
            'checks' => $presets->checks($application),
        ]);
    }

    public function store(
        SaveWorkerRequest $request,
        Application $application,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
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

    /** start | stop | restart. */
    public function control(
        Request $request,
        Application $application,
        Worker $worker,
        string $action,
        WorkerSupervisor $supervisor,
        ActivityLogger $activity,
    ): JsonResponse {
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
