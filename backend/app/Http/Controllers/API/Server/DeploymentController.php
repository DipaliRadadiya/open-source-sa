<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\DeploymentTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateDeploySettingsRequest;
use App\Http\Resources\DeploymentResource;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DeploymentController extends Controller
{
    /**
     * Newest first — the only order this list is ever read in.
     */
    public function index(Application $application): JsonResponse
    {
        $deployments = $application->deployments()
            ->with('user')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'deployments' => DeploymentResource::collection($deployments)->resolve(),
            'settings' => $this->settings($application),
        ]);
    }

    /**
     * One deploy, with its output. Its own endpoint because the output is the
     * expensive part — a list of fifty carrying full build logs is a response
     * nobody asked for.
     */
    public function show(Application $application, Deployment $deployment): JsonResponse
    {
        abort_unless($deployment->application_id === $application->id, 404);

        return response()->json([
            'deployment' => DeploymentResource::make($deployment->load('user'))->resolve(),
        ]);
    }

    /**
     * Start a deploy. Returns 202 with the row already created, so the screen
     * has something to show and poll before a worker picks the job up.
     */
    public function store(Application $application, DeploymentRecorder $recorder): JsonResponse
    {
        $deployment = $recorder->open($application, DeploymentTrigger::Manual, Auth::id());

        DeployApplication::dispatch($application->id, Auth::id(), $deployment->id);

        return response()->json([
            'deployment' => DeploymentResource::make($deployment)->resolve(),
        ], 202);
    }

    /**
     * Run it again.
     *
     * Deliberately *not* a rollback. Deploys are in place — the working tree is
     * reset to the branch tip — so there is no earlier release to return to.
     * This re-runs the current branch, which is what fixes a deploy that failed
     * on a transient error, and it says so rather than implying time travel.
     */
    public function redeploy(Application $application, Deployment $deployment, DeploymentRecorder $recorder): JsonResponse
    {
        abort_unless($deployment->application_id === $application->id, 404);

        $fresh = $recorder->open($application, DeploymentTrigger::Redeploy, Auth::id());

        DeployApplication::dispatch($application->id, Auth::id(), $fresh->id);

        return response()->json([
            'deployment' => DeploymentResource::make($fresh)->resolve(),
        ], 202);
    }

    public function updateSettings(Application $application, UpdateDeploySettingsRequest $request, ActivityLogger $activityLogger): JsonResponse
    {
        $validated = $request->validated();

        $application->update($validated);

        // The script gets its own verb. It is the one setting here that changes
        // what runs on the server, so "settings updated" would bury it among
        // branch changes and a toggle.
        $activityLogger->log(
            array_key_exists('deploy_script', $validated)
                ? 'application.deploy_script_updated'
                : 'application.deploy_settings_updated',
            $application,
            ['name' => $application->name],
        );

        return response()->json(['settings' => $this->settings($application->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Application $application): array
    {
        return [
            'branch' => $application->branch ?: 'main',
            'repository' => $application->repository,

            // What will actually run, whether the user wrote it or it is still
            // falling back to the old single build command.
            'deploy_script' => app(GitDeployer::class)->script($application),
            // Whether they have written one, so the screen can offer the
            // default rather than presenting the fallback as their own text.
            'deploy_script_customised' => filled($application->deploy_script),
            'default_deploy_script' => $this->defaultScript($application),

            'auto_deploy' => (bool) $application->webhook_enabled,
            'last_commit' => $application->last_commit,
            'last_deployed_at' => $application->last_deployed_at?->format('d-m-Y H:i:s'),
            'last_deployed_at_human' => $application->last_deployed_at?->diffForHumans(),

            // The placeholders a script may use. Sent rather than hardcoded in
            // the frontend, the same way the cron command presets are.
            'placeholders' => ['{path}', '{branch}', '{domain}'],
        ];
    }

    private function defaultScript(Application $application): string
    {
        // A Node application with a recorded package manager gets that
        // tool's own install+build, not the npm-only fallback below — the
        // whole point of recording the choice at deploy time.
        if ($application->package_manager) {
            $perManager = (array) config('server.deployments.package_manager_scripts', []);

            if (isset($perManager[$application->package_manager])) {
                return "cd {path}\ngit pull origin {branch}\n".$perManager[$application->package_manager];
            }
        }

        $scripts = (array) config('server.deployments.default_scripts', []);

        return (string) ($scripts[$application->serving_profile] ?? $scripts['php'] ?? '');
    }
}
