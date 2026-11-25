<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\ConvertSupervisor;
use App\Actions\Server\Application\CreateApplication;
use App\Actions\Server\Application\DeleteApplication;
use App\Actions\Server\Application\DeleteApplicationDatabases;
use App\Actions\Server\Application\DeleteApplicationDockerResources;
use App\Actions\Server\Application\DeprovisionApplication;
use App\Actions\Server\Application\DisableApplication;
use App\Actions\Server\Application\EnableApplication;
use App\Actions\Server\Application\RestartContainerApplication;
use App\Actions\Server\Application\RunApplicationProcess;
use App\Actions\Server\Application\UpdateApplication;
use App\Actions\Server\SystemUser\DeleteSystemUser;
use App\Enums\ApplicationStatus;
use App\Enums\BackupStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\DestroyApplicationRequest;
use App\Http\Requests\Server\Application\IndexApplicationsRequest;
use App\Http\Requests\Server\Application\StoreApplicationRequest;
use App\Http\Requests\Server\Application\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\AppSidebarResource;
use App\Jobs\MeasureApplicationSize;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Models\Backup;
use App\Models\Permission;
use App\Models\Restore;
use App\Models\SystemUser;
use App\Services\Server\Applications\AdoptedSiteConfig;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\DeployQueue;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\StrandedProvisioning;
use App\Services\Server\Certificates\CertificateLock;
use App\Services\VisiblePermissions;
use App\Support\ListSearch;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplicationController extends Controller
{
    public function index(IndexApplicationsRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        $applications = Application::query()
            // Eager: `ApplicationResource` reads `botRules` per row. Loaded here and in `show()` so
            // the two cannot disagree about whether the field exists.
            ->with('systemUser', 'botRules', 'latestContainerRedeploy')
            // Exact matches on indexed columns (validated in the request). A paused site is
            // `active` in the column, so Running excludes it and Paused finds it by `disabled_at`.
            ->when($filter['status'] ?? null, fn ($query, $status) => match ($status) {
                IndexApplicationsRequest::PAUSED => $query->whereNotNull('disabled_at'),
                ApplicationStatus::Active->value => $query->where('status', $status)->whereNull('disabled_at'),
                default => $query->where('status', $status),
            })
            ->when($filter['site_type'] ?? null, fn ($query, $type) => $query->where('site_type', $type))
            // Name or domain; the helper groups the OR and is case-insensitive on every database.
            ->when($search !== '', fn ($query) => ListSearch::apply($query, $search, ['name', 'domain']));

        $applications = ListSort::apply(
            $applications,
            $request->validated('sort'),
            IndexApplicationsRequest::SORTS,
            nullsSmallest: IndexApplicationsRequest::NULLS_SMALLEST,
            caseInsensitive: ['name'],
        )->paginate($request->validated('per_page', IndexApplicationsRequest::PER_PAGE));

        // Never-measured sites are queued for it (capped and deduplicated by the job). Not inline:
        // `du` walks every inode and would load the disk serving the sites. Over every application,
        // not the page, so later pages are not left unmeasured.
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
        $application = $action->execute($request->creation());

        return response()->json([
            'application' => ApplicationResource::make($application)->resolve(),
            // Created, but with something the user should know (e.g. a container port the image
            // does not declare). Always a list.
            'warnings' => $request->warnings(),
        ], 201);
    }

    public function show(Application $application): JsonResponse
    {
        // `botRules` must be loaded: the resource exposes `bot_blocked` / `bot_allowed` through
        // `whenLoaded`, so without it the keys are **absent**, not empty. The Bot Blocker screen
        // would then save `[]`, which the API reads as "clear them" (absent means "leave alone").
        return response()->json([
            'application' => ApplicationResource::make($application->load('systemUser', 'botRules', 'latestCheckout', 'latestContainerRedeploy'))->resolve(),
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
     * Retry provisioning after a failure. Explicit, never automatic: repeating a server mutation
     * whose failure the user has not seen risks half-applied state.
     */
    public function provision(Application $application): JsonResponse
    {
        // A second click while queued/running must not reset progress or enqueue concurrent writes.
        if ($application->status === ApplicationStatus::Provisioning) {
            return response()->json([
                'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
            ], 202);
        }

        // Persist before dispatch: a fast worker could otherwise mark the site active first.
        $application->update(['status' => ApplicationStatus::Provisioning, 'failed_step' => null, 'failed_reason' => null, 'reference' => null]);

        // Opened here, like a deployment row, so Background jobs shows the retry as waiting.
        app(ApplicationRuns::class)->open($application, ApplicationRun::INSTALL, Auth::id());

        ProvisionApplication::queueOrFail($application->id, Auth::id());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }

    /**
     * Fetch the application's code from git.
     *
     * Only for git applications: a one-click or blank site has nothing to pull.
     */
    public function deploy(Application $application): JsonResponse
    {
        abort_unless($application->site_type === 'git', 422, __('errors/application.not_a_git_application'));
        abort_if($application->gitAccountMissing(), 422, __('errors/application.git_account_missing'));

        // Records history like any other deploy (this endpoint predates the Deployment screen).
        app(DeployQueue::class)->queue($application, DeploymentTrigger::Manual, Auth::id());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }

    /**
     * Whether a port can be used, before the user submits the form.
     *
     * A read, so it needs only `application`. Three answers: taken, free, or free but named after
     * a service (a warning, not an error). See PortAllocator::inspect().
     */
    public function portCheck(Request $request, PortAllocator $ports): JsonResponse
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'between:1024,65535'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        // Excluded so an application is not told its own port is taken.
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
     * Refused for an application that has none (a PHP site): a no-op must not report success.
     *
     * A Docker application has containers instead, and restarting them is the one action that
     * applies. Start and stop stay refused: for a container app those are Pause and Resume.
     */
    public function process(
        Application $application,
        string $action,
        ProcessSupervisor $supervisor,
        RunApplicationProcess $run,
        RestartContainerApplication $restartContainers,
    ): JsonResponse {
        abort_unless(in_array($action, RunApplicationProcess::ACTIONS, true), 404);

        if ($action === 'restart' && $application->serving_profile === 'docker') {
            $result = $restartContainers->execute($application->load('systemUser'));
        } else {
            abort_unless(
                $supervisor->runs($application),
                422,
                __('errors/application.no_process', ['name' => $application->name]),
            );

            $result = $run->execute($application->load('systemUser'), $action);
        }

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
     * Move an adopted application onto a systemd unit of ours.
     *
     * Only offered for one still under the old panel's PM2 daemon. **This restarts the
     * application** (one supervisor must release the port before the other binds it), so it is
     * its own endpoint, never a side effect of an update.
     *
     * A failure has already put the application back under PM2 and started it; the 500 says so.
     */
    public function convertSupervisor(
        Application $application,
        ProcessSupervisor $supervisor,
        ConvertSupervisor $convert,
        ApplicationProvisioner $provisioner,
    ): JsonResponse {
        abort_unless(
            $supervisor->legacy($application),
            422,
            __('errors/application.not_adopted', ['name' => $application->name]),
        );

        $application->load('systemUser');

        abort_unless(
            $convert->convertible($application),
            422,
            __('errors/application.convert_no_entrypoint', ['name' => $application->name]),
        );

        try {
            $convert->toSystemd($application, $provisioner->documentRoot($application));
        } catch (ProvisioningFailedException $e) {
            return response()->json([
                'message' => __('errors/application.convert_failed', ['name' => $application->name]),
                'reference' => $e->reference,
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
     * Removing an application also stops it being served. Files are kept unless
     * `remove_files=true` and databases unless `remove_databases=true`: deleting a panel record
     * must not silently destroy code or data.
     *
     * Answers 200 even when a database could not be dropped (the site is gone); what survived
     * is named in the body.
     */
    public function destroy(
        Application $application,
        DestroyApplicationRequest $request,
        DeprovisionApplication $deprovision,
        DeleteApplication $action,
        DeleteApplicationDatabases $databases,
        DeleteApplicationDockerResources $dockerResources,
        StrandedProvisioning $stranded,
        ApplicationRuns $runs,
        AdoptedSiteConfig $adopted,
        CertificateLock $certificates,
    ): JsonResponse {
        // First, before anything changes: deleting the files of a site that an adopted worker (from
        // a shared supervisor file or a systemd unit the panel cannot prove it owns) still runs from
        // is refused, naming the worker. Read-only; without `remove_files` the delete goes ahead.
        if ($request->boolean('remove_files')) {
            $adopted->refuseUnprovenWorkers($application);
        }

        // Takes turns with certificate issuance (CertificateLock), before the first write below, so a
        // delete that waits out an issuance answers 503 with nothing changed. Held until the row is
        // gone, so the certificate step removes a lineage that exists instead of losing to certbot's lock.
        $certificateLock = $certificates->forDelete($application);

        try {
            // A queued worker can still be writing this site's files and config, so deleting the
            // record would leave those untracked, unless its install job is provably gone
            // (StrandedProvisioning), which would otherwise make the site undeletable.
            if ($stranded->close($application)) {
                $application->refresh();
            }

            abort_if($application->status === ApplicationStatus::Provisioning, 503, __('errors/server.busy'));

            // A running Pull and redeploy would `compose up` the containers again after the delete.
            abort_if($runs->redeployInFlight($application) !== null, 503, __('errors/server.busy'));

            // Backups are archived on delete; one still being written, or a restore replacing the
            // site, would finish against nothing. Refused before anything is removed.
            $backupBusy = Backup::query()->where('application_id', $application->id)
                ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
                ->exists()
                || Restore::inProgressFor($application->id);

            if ($backupBusy) {
                throw ValidationException::withMessages([
                    'application' => [__('backup.errors.delete_site_backup_running')],
                ]);
            }

            // Read before the record goes: `databases.application_id` is `nullOnDelete`, so afterwards
            // the databases to remove can no longer be found.
            $attached = $request->boolean('remove_databases')
                ? $application->databases()->with('users')->get()
                : null;

            // Planned BEFORE the row is deleted ("does another site mount this" queries applications),
            // applied AFTER deprovision: `docker volume rm` / `network rm` refuse resources still
            // referenced by this site's containers until `compose down` has run.
            $dockerPlan = $request->boolean('remove_docker_resources')
                ? $dockerResources->plan($application)
                : null;

            // Its images too, unless turned off; read now, while the containers still say which they run.
            if ($application->serving_profile === 'docker' && $request->removeDockerImages()) {
                $dockerPlan = $dockerResources->planImages($application, $dockerPlan ?? ['volumes' => [], 'networks' => [], 'kept' => []]);
            }

            // The Docker opt-in reaches `compose down` as `--volumes` (see ContainerSupervisor).
            // The owner is read before the row goes, as the databases are.
            $owner = $request->boolean('remove_system_user') ? $application->systemUser : null;

            $deprovision->execute(
                $application,
                $request->boolean('remove_files'),
                $request->boolean('remove_docker_resources'),
            );
            $action->execute($application);
        } finally {
            $certificateLock->release();
        }

        if ($dockerPlan !== null) {
            $dockerResources->apply($application, $dockerPlan);
        }

        // Site first, databases after, never the reverse: a database dropped before a failed site
        // delete is the data of a site still serving traffic.
        $outcome = $attached === null
            ? null
            : $databases->execute($attached, $application->id);

        return response()->json(['deleted' => true]
            + ($outcome?->toArray() ?? [])
            + ($owner === null ? [] : ['system_user' => $this->removeOwner($owner)]));
    }

    /**
     * The site's Linux account, removed when it owns nothing else. Always after the site, and
     * never failing the delete that already happened: the answer says what became of it.
     *
     * @return array{username: string, outcome: string, message: string}
     */
    private function removeOwner(SystemUser $owner): array
    {
        $outcome = 'removed';

        if ($owner->applications()->exists()) {
            $outcome = 'still_used';
        } else {
            // The site's PHP workers can outlive its vhost (OpenLiteSpeed's lsphp), so they are
            // ended; anything else the account runs (SSH session, shell) is left alone and answered
            // as "still running something".
            $deleter = app(DeleteSystemUser::class);

            for ($attempt = 0; ; $attempt++) {
                try {
                    $deleter->execute($owner);
                    $outcome = 'removed';

                    break;
                } catch (ValidationException) {
                    $outcome = 'has_processes';

                    if ($attempt >= 4 || ! $deleter->endPhpWorkers($owner->username)) {
                        break;
                    }

                    Sleep::for(1)->seconds();
                } catch (Throwable) {
                    $outcome = 'failed';

                    break;
                }
            }
        }

        return [
            'username' => $owner->username,
            'outcome' => $outcome,
            'message' => __('application.system_user_removal.'.$outcome, ['username' => $owner->username]),
        ];
    }

    /**
     * The app sidebar for this specific application.
     *
     * Filtered by what this app type supports (SiteType) and what the user has been granted
     * (permission pivot): a WordPress site has no "Workers"; no `app_staging` means no Staging.
     *
     * Grouped by sub-level so the frontend renders section headers without hardcoding them.
     */
    public function sidebar(Application $application, VisiblePermissions $permissions): JsonResponse
    {
        // Resolved by the same service `/permissions` uses.
        $items = AppSidebarResource::collection(
            $permissions->for(Auth::user(), 'application', $application)
        )->resolve();

        return response()->json(['items' => $items]);
    }

    /**
     * Measure this site's directory now.
     *
     * Its own call, not part of the listing: `du` walks every inode (a site with node_modules has
     * a hundred thousand), so measuring every site on every list would be as slow as the heaviest
     * site, and nothing measures on a timer either. The stored size is the last measurement; this
     * button measures now.
     */
    public function measureDirectorySize(Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            'directory_size' => $files->applicationSize($application, refresh: true),
        ]);
    }
}
