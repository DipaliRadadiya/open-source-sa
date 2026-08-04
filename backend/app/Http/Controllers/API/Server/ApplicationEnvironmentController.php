<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SaveEnvironmentRequest;
use App\Http\Resources\ApplicationEnvironmentResource;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\EnvironmentInspector;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\ServerOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The application's `.env`, edited as one file.
 *
 * Whole-file rather than per-key: comments, ordering and blank-line grouping
 * belong to whoever wrote them, and a key-by-key API would rebuild the file on
 * every save and quietly discard all three.
 */
class ApplicationEnvironmentController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        // No site-type check here: `permission:app_environment` already
        // refuses a WordPress site with a 404, in one place, for every app
        // route. A second answer in the controller would only be a second
        // thing to keep in step.
        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application)->resolve(),
        ]);
    }

    /**
     * Replace the file.
     *
     * Then do whatever that framework needs for the change to actually take
     * effect — a Laravel site with a cached config and a Node service both
     * ignore a saved `.env` until told otherwise, and a screen that says
     * "Saved" while nothing changed is worse than one that fails.
     */
    public function update(
        SaveEnvironmentRequest $request,
        Application $application,
        ApplicationEnvironment $files,
        EnvironmentInspector $inspector,
        FrameworkDetector $detector,
        ActivityLogger $activity,
    ): JsonResponse {
        $raw = (string) $request->validated('raw');
        $framework = $detector->detect($application);

        // Syntax errors are refused; warnings are not. A duplicate key or a
        // debug flag is the user's business, but a line the parser cannot read
        // means the file they are about to install does not work at all.
        $errors = array_values(array_filter(
            $inspector->checks($raw, $framework),
            fn (array $check): bool => $check['severity'] === 'error' && str_starts_with($check['code'], 'syntax_'),
        ));

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'raw' => array_map(fn (array $check): string => $check['detail'], $errors),
            ]);
        }

        $before = $files->exists($application) ? $files->read($application) : '';

        $files->write($application, $raw);

        $applied = $this->apply($application, $framework, $detector);
        $restarted = $this->restart($application, $request->boolean('restart'));

        // Key names only. The whole point of the screen is that the values are
        // secrets, and an activity log is read by more people than the file is.
        $activity->log('application.environment_updated', $application, [
            'name' => $application->name,
            'keys' => implode(', ', $this->changedKeys($before, $raw)) ?: '—',
        ]);

        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application->fresh())->resolve(),
            'applied' => $applied,
            'restarted' => $restarted,
        ]);
    }

    /** Put a previous save back. */
    public function restore(
        Request $request,
        Application $application,
        ApplicationEnvironment $files,
        FrameworkDetector $detector,
        ActivityLogger $activity,
    ): JsonResponse {
        $name = (string) $request->string('backup')->trim();

        $files->restore($application, $name);

        $this->apply($application, $detector->detect($application), $detector);
        $this->restart($application, $request->boolean('restart'));

        $activity->log('application.environment_restored', $application, [
            'name' => $application->name,
            'backup' => $name,
        ]);

        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application->fresh())->resolve(),
        ]);
    }

    /**
     * Clear a cached config, when there is one. Reported rather than silent:
     * "we also cleared your config cache" is information, and its failure is
     * the difference between a save that works and one that looks like it did.
     */
    private function apply(Application $application, string $framework, FrameworkDetector $detector): bool
    {
        if (! $detector->requiresApply($application, $framework)) {
            return false;
        }

        $command = $detector->applyCommand($application, $framework);

        if ($command === null) {
            return false;
        }

        return app(ServerOps::class)->run(
            $command,
            ['feature' => 'application', 'op' => 'env_apply', 'application' => $application->id],
            timeout: 120,
        )->ok;
    }

    private function restart(Application $application, bool $requested): bool
    {
        $processes = app(ProcessSupervisor::class);

        if (! $requested || ! $processes->runs($application)) {
            return false;
        }

        return $processes->restart($application)->ok;
    }

    /**
     * Which keys changed, added or removed — names only, never values.
     *
     * @return array<int, string>
     */
    private function changedKeys(string $before, string $after): array
    {
        // Compared from the raw text, not from the parsed variables: the
        // parser blanks secret values, so DB_PASSWORD would compare null to
        // null and a password change would be recorded as no change at all —
        // exactly the edit most worth having in the log.
        $keyed = function (string $raw): array {
            $map = [];

            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) === 1) {
                    $map[$m[1]] = trim($m[2]);
                }
            }

            return $map;
        };

        $old = $keyed($before);
        $new = $keyed($after);

        $changed = array_keys(array_merge(
            array_diff_key($new, $old),
            array_diff_key($old, $new),
        ));

        foreach ($new as $key => $value) {
            if (array_key_exists($key, $old) && $old[$key] !== $value) {
                $changed[] = $key;
            }
        }

        sort($changed);

        return array_values(array_unique($changed));
    }
}
