<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\RelinkGitAccount;
use App\Enums\DeploymentTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateDeploySettingsRequest;
use App\Http\Requests\Server\Application\UpdateGitAccountRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\DeploymentResource;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\DeployQueue;
use App\Services\Server\Applications\DeployScriptPhp;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DeploymentController extends Controller
{
    /** Newest first. */
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
     * The newest deploy only, or null — what the Deployment screen polls.
     *
     * One indexed row in the history-row shape (no build output; that stays on `show`), so the
     * screen can compare `id` and `status` with its top row and reload only on a change. This
     * picks up deploys started by a push.
     */
    public function latest(Application $application): JsonResponse
    {
        $deployment = $application->deployments()->with('user')->orderByDesc('id')->first();

        return response()->json([
            'latest' => $deployment === null ? null : DeploymentResource::make($deployment)->resolve(),
        ]);
    }

    /** One deploy, with its output (its own endpoint: build logs are too heavy for the list). */
    public function show(Application $application, Deployment $deployment): JsonResponse
    {
        abort_unless($deployment->application_id === $application->id, 404);

        return response()->json([
            'deployment' => DeploymentResource::make($deployment->load('user'))->resolve(),
        ]);
    }

    /** Start a deploy. Returns 202 with the row already created, so the screen can poll it. */
    public function store(Application $application, DeployQueue $deploys): JsonResponse
    {
        $this->refuseWithoutAccount($application);

        $deployment = $deploys->queue($application, DeploymentTrigger::Manual, Auth::id());

        return response()->json([
            'deployment' => DeploymentResource::make($deployment)->resolve(),
        ], 202);
    }

    /**
     * Run it again. Deliberately *not* a rollback: deploys are in place (the working tree is
     * reset to the branch tip), so there is no earlier release. This re-runs the current branch.
     */
    public function redeploy(Application $application, Deployment $deployment, DeployQueue $deploys): JsonResponse
    {
        abort_unless($deployment->application_id === $application->id, 404);
        $this->refuseWithoutAccount($application);

        $fresh = $deploys->queue($application, DeploymentTrigger::Redeploy, Auth::id());

        return response()->json([
            'deployment' => DeploymentResource::make($fresh)->resolve(),
        ], 202);
    }

    /**
     * Re-point the application at a different account, repository or URL.
     *
     * Not a plain column write: the candidate credential is checked against the repository, and
     * a rejection is a 422 the user can act on.
     */
    public function updateGitAccount(
        Application $application,
        UpdateGitAccountRequest $request,
        RelinkGitAccount $relink,
    ): JsonResponse {
        return response()->json([
            'application' => ApplicationResource::make(
                $relink->execute($application, $request->validated())->load('systemUser')
            )->resolve(),
        ]);
    }

    public function updateSettings(Application $application, UpdateDeploySettingsRequest $request, ActivityLogger $activityLogger): JsonResponse
    {
        $validated = $request->validated();

        $application->update($validated);

        // The script gets its own verb: it is the one setting that changes what runs on the server.
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

            // What will actually run: the user's script or the fallback build command.
            'deploy_script' => app(GitDeployer::class)->script($application),
            // Whether the user wrote one. A saved copy of the default (ignoring line endings and
            // trailing space) does not count.
            'deploy_script_customised' => filled($application->deploy_script)
                && $this->normalised($application->deploy_script) !== $this->normalised($this->defaultScript($application)),
            'default_deploy_script' => $this->defaultScript($application),

            'auto_deploy' => (bool) $application->webhook_enabled,
            'last_commit' => $application->last_commit,
            // Differs from `last_commit` after a deploy that failed once checked out.
            // See Application::codeOnDisk().
            'code_on_disk' => $application->codeOnDisk(),
            'last_deployed_at' => $application->last_deployed_at?->format('d-m-Y H:i:s'),
            'last_deployed_at_human' => $application->last_deployed_at?->diffForHumans(),

            // Placeholders a script may use, plus a `{PHP84}`-style variable per installed version.
            'placeholders' => ['{path}', '{branch}', '{domain}', '{php}', ...array_keys(app(DeployScriptPhp::class)->aliases())],
            // What each one becomes for this site, from the code that does the substituting.
            'placeholder_values' => app(GitDeployer::class)->placeholderValues($application),
        ];
    }

    private function normalised(string $script): string
    {
        return implode("\n", array_map('rtrim', preg_split('/\r\n|\r|\n/', trim($script)) ?: []));
    }

    private function defaultScript(Application $application): string
    {
        // A Node app with a recorded package manager gets that tool's own install+build.
        if ($application->package_manager) {
            $perManager = (array) config('server.deployments.package_manager_scripts', []);

            if (isset($perManager[$application->package_manager])) {
                return "cd {path}\n".$perManager[$application->package_manager];
            }
        }

        $scripts = (array) config('server.deployments.default_scripts', []);

        return (string) ($scripts[$application->serving_profile] ?? $scripts['php'] ?? '');
    }

    /**
     * Refuse before a row and a job are made: a site whose git account was disconnected has no
     * credential or URL, so its deploy could only fail. The screen disables the button too.
     */
    private function refuseWithoutAccount(Application $application): void
    {
        abort_if($application->gitAccountMissing(), 422, __('errors/application.git_account_missing'));
    }
}
