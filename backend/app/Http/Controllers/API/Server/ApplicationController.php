<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateApplication;
use App\Actions\Server\Application\DeleteApplication;
use App\Actions\Server\Application\DeprovisionApplication;
use App\Actions\Server\Application\RunApplicationProcess;
use App\Actions\Server\Application\UpdateApplication;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreApplicationRequest;
use App\Http\Requests\Server\Application\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Jobs\DeployApplication;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Services\Server\Applications\ProcessSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index(): JsonResponse
    {
        $applications = Application::query()->with('systemUser')->latest('id')->get();

        return response()->json([
            'applications' => ApplicationResource::collection($applications)->resolve(),
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $action): JsonResponse
    {
        $application = $action->execute($request->validated());

        return response()->json([
            'application' => ApplicationResource::make($application)->resolve(),
        ], 201);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'application' => ApplicationResource::make($application->load('systemUser'))->resolve(),
        ]);
    }

    public function update(Application $application, UpdateApplicationRequest $request, UpdateApplication $action): JsonResponse
    {
        $application = $action->execute($application, $request->validated());

        return response()->json([
            'application' => ApplicationResource::make($application)->resolve(),
        ]);
    }

    /**
     * Retry provisioning after a failure. Explicit rather than automatic: the
     * job does not retry itself, because repeating a server mutation the user
     * has not seen the reason for is how half-applied state happens.
     */
    public function provision(Application $application): JsonResponse
    {
        ProvisionApplication::dispatch($application->id);

        $application->update(['status' => ApplicationStatus::Provisioning, 'failed_step' => null, 'reference' => null]);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }

    /**
     * Fetch the application's code from git.
     *
     * Only for git applications — there is nothing to pull for a one-click or
     * blank site, and pretending otherwise would just fail confusingly.
     */
    public function deploy(Application $application): JsonResponse
    {
        abort_unless($application->site_type === 'git', 422, __('errors/application.not_a_git_application'));

        DeployApplication::dispatch($application->id);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }

    /**
     * Start, stop or restart the application's own process.
     *
     * Refused for an application that has none: a PHP site has no process to
     * act on, and reporting success for a no-op would teach the user that the
     * button does something.
     */
    public function process(
        Application $application,
        string $action,
        ProcessSupervisor $supervisor,
        RunApplicationProcess $run,
    ): JsonResponse {
        abort_unless(in_array($action, RunApplicationProcess::ACTIONS, true), 404);

        abort_unless(
            $supervisor->runs($application),
            422,
            __('errors/application.no_process', ['name' => $application->name]),
        );

        $result = $run->execute($application->load('systemUser'), $action);

        if ($result->failed()) {
            return response()->json([
                'message' => __('errors/application.process_failed', ['action' => $action]),
                'reference' => $result->reference,
            ], 500);
        }

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ]);
    }

    /**
     * Removing an application also stops it being served. The site's files are
     * kept unless `remove_files=true` is asked for explicitly — deleting a
     * panel record must not silently destroy someone's code.
     */
    public function destroy(
        Application $application,
        Request $request,
        DeprovisionApplication $deprovision,
        DeleteApplication $action,
    ): JsonResponse {
        $deprovision->execute($application, $request->boolean('remove_files'));
        $action->execute($application);

        return response()->json(['deleted' => true]);
    }
}
