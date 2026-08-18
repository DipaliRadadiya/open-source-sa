<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateApplication;
use App\Actions\Server\Application\DeleteApplication;
use App\Actions\Server\Application\DeprovisionApplication;
use App\Actions\Server\Application\DisableApplication;
use App\Actions\Server\Application\EnableApplication;
use App\Actions\Server\Application\RunApplicationProcess;
use App\Actions\Server\Application\UpdateApplication;
use App\Enums\ApplicationStatus;
use App\Enums\DeploymentTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\IndexApplicationsRequest;
use App\Http\Requests\Server\Application\StoreApplicationRequest;
use App\Http\Requests\Server\Application\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\AppSidebarResource;
use App\Jobs\DeployApplication;
use App\Jobs\MeasureApplicationSize;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Permission;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\VisiblePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplicationController extends Controller
{
    public function index(IndexApplicationsRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));

        $applications = Application::query()
            ->with('systemUser')
            ->when($search !== '', function ($query) use ($search) {
                // Name or domain — the two things somebody has in mind when
                // they go looking for a site. Grouped so the search does not
                // escape into an OR across the whole query if a filter is
                // added here later.
                $like = '%'.$search.'%';

                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('domain', 'like', $like));
            })
            ->latest('id')
            ->paginate($request->validated('per_page', IndexApplicationsRequest::PER_PAGE));

        // Sites that have never been measured get queued for it, capped and
        // deduplicated by the job. Not measured inline: `du` walks every inode
        // under a site, so counting each one here would make this the slowest
        // page in the panel and put that load on the disk serving the sites.
        //
        // Over every application, not the page. Backfilling only what is
        // visible would leave page three unmeasured until somebody happened to
        // scroll to it — and the job is already capped and deduplicated, which
        // is what makes asking for all of them cheap.
        MeasureApplicationSize::backfill(Application::query()->get());

        return response()->json([
            'applications' => ApplicationResource::collection($applications->items())->resolve(),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'last_page' => $applications->lastPage(),
            ],
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
        // A second click while this job is queued/running must not reset its
        // progress or enqueue concurrent writes to the same site.
        if ($application->status === ApplicationStatus::Provisioning) {
            return response()->json([
                'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
            ], 202);
        }

        // Persist before dispatch. A fast worker can otherwise finish and mark
        // the site active before this request overwrites it as provisioning.
        $application->update(['status' => ApplicationStatus::Provisioning, 'failed_step' => null, 'reference' => null]);

        ProvisionApplication::dispatch($application->id, Auth::id());

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

        // Records like any other deploy. This endpoint predates the
        // Deployment screen and stays for compatibility, but a deploy that
        // leaves no history depending on which button started it would be a
        // gap nobody could explain.
        $deployment = app(DeploymentRecorder::class)->open(
            $application,
            DeploymentTrigger::Manual,
            Auth::id(),
        );

        DeployApplication::dispatch($application->id, Auth::id(), $deployment->id);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }

    /**
     * Whether a port can be used, before the user submits the form.
     *
     * A read, so it needs only `application` — checking a port is not a
     * change. Three answers rather than two: taken, free, or free but named
     * after a service, which the screen should show as a warning rather than
     * an error. See PortAllocator::inspect().
     */
    public function portCheck(Request $request, PortAllocator $ports): JsonResponse
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'between:1024,65535'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        // Excluded so an application editing its own settings is not told its
        // current port is taken — by itself.
        $except = filled($validated['application_id'] ?? null)
            ? Application::find($validated['application_id'])
            : null;

        $result = $ports->inspect((int) $validated['port'], $except);

        return response()->json([
            'port_check' => [
                ...$result,
                'message' => $result['reason'] === null
                    ? __('application.port_free', ['port' => $result['port']])
                    : __('validation.port_'.$result['reason'], [
                        'port' => $result['port'],
                        'service' => $result['service'] ?? '',
                    ]),
            ],
        ]);
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
     * Take a site offline without deleting it — a vhost swap to a small
     * "unavailable" page. Everything else (files, database, a supervised
     * process) is left alone; see `enable()` for the reverse.
     */
    public function disable(Application $application, DisableApplication $action): JsonResponse
    {
        $action->execute($application);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ]);
    }

    public function enable(Application $application, EnableApplication $action): JsonResponse
    {
        $action->execute($application);

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
        // A queued worker can still be writing this site's files and config.
        // Deleting its record now would leave those mutations untracked.
        abort_if($application->status === ApplicationStatus::Provisioning, 503, __('errors/server.busy'));

        $deprovision->execute($application, $request->boolean('remove_files'));
        $action->execute($application);

        return response()->json(['deleted' => true]);
    }

    /**
     * The app sidebar for this specific application.
     *
     * Filtered two ways: what this app type supports (SiteType), and what
     * the user has been granted (permission pivot). A WordPress site does not
     * show "Workers"; a user without `app_staging` does not see Staging.
     *
     * Grouped by sub-level so the frontend can render section headers without
     * hardcoding them.
     */
    public function sidebar(Application $application, VisiblePermissions $permissions): JsonResponse
    {
        // Resolved by the same service `/permissions` uses. This method used
        // to build its own answer from `$user->permissions()` — a relation
        // deleted when per-user grants gave way to roles — so the sidebar had
        // been a 500 since that refactor.
        $items = AppSidebarResource::collection(
            $permissions->for(Auth::user(), 'application', $application)
        )->resolve();

        return response()->json(['items' => $items]);
    }

    /**
     * Measure this site's directory now.
     *
     * Its own call rather than something the listing does: `du` walks every
     * inode, so the cost is the file count — a site with node_modules is a
     * hundred thousand of them — and doing that for every site on every list
     * would make the Applications screen as slow as the heaviest site on the
     * box. Nothing measures it on a timer either, for the same reason on the
     * machine that is also serving those sites.
     *
     * So the stored size is whatever was last measured, and the response says
     * when that was. This is the button that makes it now.
     */
    public function measureDirectorySize(Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            'directory_size' => $files->applicationSize($application, refresh: true),
        ]);
    }
}
