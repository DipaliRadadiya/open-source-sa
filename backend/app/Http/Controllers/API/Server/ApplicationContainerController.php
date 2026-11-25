<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\StartContainerRedeploy;
use App\Actions\Server\Application\UpdateContainerCompose;
use App\Actions\Server\Application\UpdateContainerSettings;
use App\Actions\Server\SystemUser\DisableContainerLogin;
use App\Actions\Server\SystemUser\EnableContainerLogin;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\EnableContainerLoginRequest;
use App\Http\Requests\Server\Application\UpdateComposeRequest;
use App\Http\Requests\Server\Application\UpdateContainerRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\ApplicationRunResource;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Recipes\RecipeCredentials;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\ContainerAccess;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\DnsVerifier;
use App\Services\Server\AppShell\ContainerLoginInfo;
use App\Services\Server\ServerPublicIp;
use App\Services\Server\Settings\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ApplicationContainerController extends Controller
{
    /**
     * The credentials the panel generated when it installed this app.
     *
     * Its own endpoint on purpose: these are live database passwords, so they stay off
     * `ApplicationResource` (every payload, list and browser cache). Asking is recorded.
     *
     * Read-only: rotating one means rewriting the compose file AND the credential inside the
     * running database, which can leave the two disagreeing.
     */
    public function secrets(Application $application, ActivityLogger $log): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        // Logged before returning: a response that is never read still handed the values over.
        $log->log('application.container_secrets_viewed', $application, [
            'name' => $application->name,
            'keys' => array_keys((array) ($application->docker_secrets ?? [])),
        ]);

        return response()->json([
            'secrets' => (array) ($application->docker_secrets ?? []),
            'credentials' => app(RecipeCredentials::class)->for($application),
        ]);
    }

    /**
     * Record that somebody has saved this site's generated credentials.
     *
     * Its own endpoint, not a side effect of `secrets()`: these values cannot be rotated from
     * the panel, so a card that vanished on render would lose an unrecoverable password to a
     * stray refresh. Marking them seen must be something a person did.
     *
     * Idempotent, and it keeps the FIRST acknowledgement (when they stopped being unseen).
     */
    public function acknowledgeSecrets(Application $application, ActivityLogger $log): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        if ($application->credentials_seen_at === null) {
            $application->forceFill(['credentials_seen_at' => now()])->save();

            // Not the same as the view `secrets()` records: a page can be opened and closed.
            $log->log('application.credentials_acknowledged', $application, [
                'name' => $application->name,
            ]);
        }

        return response()->json([
            'application' => new ApplicationResource($application->fresh(['systemUser', 'registry'])),
        ]);
    }

    /**
     * The compose file this site runs, for the editor.
     *
     * A Simple-mode site has no stored file (the panel renders one from its fields on every
     * deploy), so this returns that render and flags it `generated`. Editing takes it over
     * one-way: the stored text wins and the fields stop being the source.
     *
     * Read-only: the supervisor's pasted-file path records the published port as a side
     * effect, which a GET must not do.
     */
    public function compose(
        Application $application,
        ContainerSupervisor $containers,
        ApplicationProvisioner $provisioner,
    ): JsonResponse {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        $stored = (string) $application->compose;
        $generated = trim($stored) === '';

        try {
            $compose = $generated
                ? $containers->generated($application, $provisioner->documentRoot($application))
                : $stored;
        } catch (ProvisioningFailedException $e) {
            // No container port: no file to show, and the user's to fix, not a server fault.
            return response()->json([
                'message' => __('application.failure_reason.'.$e->reason),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'compose' => $compose,
            // So the editor can warn that saving takes the file over.
            'generated' => $generated,
        ]);
    }

    /**
     * What the Command-line access card needs.
     *
     * Same permission as reading the compose file, since it says no more than the file does
     * (container names; the app's own system user as flags and counts only). The server's full
     * list of system users is a separate grant: `ssh_users` is filled only for a viewer who
     * holds `system_user`, and never with a password or a key.
     */
    public function access(Request $request, Application $application): JsonResponse
    {
        return $this->accessResponse($request, $application);
    }

    /**
     * Turn container login on for this app's own system user: its SSH login then opens this
     * app's container, with no sudo and no docker group. Answers with the card's new state.
     */
    public function enableAccess(
        EnableContainerLoginRequest $request,
        Application $application,
        EnableContainerLogin $action,
    ): JsonResponse {
        // Not a container, or no owner: refused by the request before this runs.
        $action->execute($application->systemUser, $request->safe()->only(['ssh_key', 'password']));

        return $this->accessResponse($request, $application->refresh());
    }

    /**
     * Turn it off: the user leaves the group, and its SSH (if on) is an ordinary login again.
     */
    public function disableAccess(
        Request $request,
        Application $application,
        DisableContainerLogin $action,
    ): JsonResponse {
        $this->assertContainerApp($application);
        abort_if($application->systemUser === null, 422, __('errors/system-user.container_login_no_owner'));

        $action->execute($application->systemUser);

        return $this->accessResponse($request, $application->refresh());
    }

    /** The 404/422 the other container endpoints give a site that is not a container app. */
    private function assertContainerApp(Application $application): void
    {
        abort_unless($application->supports('app_compose'), 404);
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );
    }

    private function accessResponse(Request $request, Application $application): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        try {
            $list = app(ContainerAccess::class)->containers($application);
        } catch (ProvisioningFailedException $e) {
            // As in compose(): a Simple-mode site with no container port has nothing to name yet.
            return response()->json([
                'message' => __('application.failure_reason.'.$e->reason),
                'reason' => $e->reason,
            ], 422);
        }

        $users = $request->user()?->canView('system_user')
            ? SystemUser::query()->orderBy('username')->get()->map(fn (SystemUser $user): array => [
                'username' => $user->username,
                'ssh_access' => (bool) $user->ssh_access,
                'sudo' => (bool) $user->sudo,
                // Whether the panel knows one (what `sudo` asks for).
                'has_password' => $user->password !== null,
            ])->all()
            : [];

        $security = app(SecuritySettings::class);

        // Fall back to the host this request came in on.
        $host = app(ServerPublicIp::class)->detect(fn () => app(DnsVerifier::class)->serverIp()) ?? $request->getHost();
        $port = $security->port();

        return response()->json([
            'host' => $host,
            'ssh_port' => $port,
            'project' => app(ContainerSupervisor::class)->project($application),
            'containers' => $list,
            'ssh_users' => $users,
            'password_login' => $security->passwordLogin(),
            ...app(ContainerLoginInfo::class)->for($application, $host, $port),
        ]);
    }

    /**
     * Replace the compose file and bring the site up on it.
     *
     * Synchronous, like the two endpoints below, so the caller gets the real failure. A file
     * that will not come up is rolled back by the action: a 422 means the site still runs what
     * it ran before.
     */
    public function updateCompose(
        UpdateComposeRequest $request,
        Application $application,
        UpdateContainerCompose $action,
    ): JsonResponse {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        $this->refuseWhileRedeploying($application);

        try {
            $updated = $action->execute($application, (string) $request->validated('compose'));
        } catch (ProvisioningFailedException $e) {
            return response()->json([
                'message' => $e->reason !== null
                    ? __('application.failure_reason.'.$e->reason)
                    : ($e->getMessage() !== '' && $e->step === 'compose_invalid'
                        ? $e->getMessage()
                        : __('errors/application.compose_apply_failed')),
                'step' => $e->step,
                'reason' => $e->reason,
                'reference' => $e->reference,
                // The site is running the file it ran before this request.
                'rolled_back' => true,
            ], 422);
        }

        return response()->json([
            'application' => ApplicationResource::make($updated)->resolve(),
        ]);
    }

    /**
     * Fetch a newer image and recreate the container on it.
     *
     * `compose up` reuses an image it already has, so a site on a floating tag never moves;
     * this is the update path (and the only one for a private image).
     *
     * Queued, unlike the settings and compose saves: a pull is a download of any size and must
     * be recorded as a run. 202 with the run; the pull's real failure (reason, step, reference)
     * lands on that run and on the application, which the screen polls. Refusals that need no
     * pull (not a container, not running, a redeploy under way) still answer here.
     */
    public function pull(Application $application, StartContainerRedeploy $action): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        $run = $action->execute($application);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser', 'registry', 'latestContainerRedeploy']))->resolve(),
            'redeploy' => ApplicationRunResource::make($run)->resolve(),
        ], 202);
    }

    /**
     * Change what a container site runs as.
     *
     * Refused for anything that is not a container: the fields mean nothing for a PHP or Node
     * site, and storing values nothing reads looks like a feature that works.
     *
     * Synchronous: rewriting the compose file and recreating one container is seconds, and the
     * caller gets the real failure.
     */
    public function update(
        UpdateContainerRequest $request,
        Application $application,
        UpdateContainerSettings $action,
    ): JsonResponse {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        $this->refuseWhileRedeploying($application);

        try {
            $updated = $action->execute(
                $application,
                Arr::except($request->validated(), ['remove_previous_network']),
                $request->boolean('remove_previous_network', true),
            );
        } catch (ProvisioningFailedException $e) {
            // The settings were saved; bringing the container up on them failed.
            return response()->json([
                'message' => $e->reason !== null
                    ? __('application.failure_reason.'.$e->reason)
                    : __('errors/application.container_apply_failed'),
                'step' => $e->step,
                'reason' => $e->reason,
                'reference' => $e->reference,
                // So the form does not offer to "retry" a change it thinks was lost.
                'saved' => true,
            ], 422);
        }

        return response()->json([
            'application' => ApplicationResource::make($updated)->resolve(),
        ]);
    }

    /**
     * A settings or compose save runs its own `compose up`; during a Pull and redeploy that
     * would race the one in its job.
     */
    private function refuseWhileRedeploying(Application $application): void
    {
        abort_if(
            app(ApplicationRuns::class)->redeployInFlight($application) !== null,
            409,
            __('errors/application.container_redeploy_running', ['name' => $application->name]),
        );
    }
}
