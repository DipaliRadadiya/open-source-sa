<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\RestoreEnvironmentRequest;
use App\Http\Requests\Server\Application\SaveEnvironmentRequest;
use App\Http\Resources\ApplicationEnvironmentResource;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\EnvironmentDiff;
use App\Services\Server\Applications\EnvironmentHistory;
use App\Services\Server\Applications\EnvironmentInspector;
use App\Services\Server\Applications\EnvironmentKeyLock;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\ServerOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The application's `.env`, edited as one file.
 *
 * Whole-file rather than per-key, so a save keeps the user's comments, ordering and blank lines.
 */
class ApplicationEnvironmentController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        // No site-type check: `permission:app_environment` already returns 404 for a WordPress site.
        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application)->resolve(),
        ]);
    }

    /**
     * Check a file before it is saved: the same checks and refusals as a save, nothing written.
     */
    public function check(
        SaveEnvironmentRequest $request,
        Application $application,
        ApplicationEnvironment $files,
        EnvironmentInspector $inspector,
        EnvironmentKeyLock $lock,
        FrameworkDetector $detector,
    ): JsonResponse {
        $raw = (string) $request->validated('raw');
        $checks = $inspector->checks($raw, $detector->detect($application));

        $refused = array_values(array_map(
            fn (array $check): string => $check['detail'],
            array_filter($checks, fn (array $check): bool => $check['severity'] === 'error' && str_starts_with($check['code'], 'syntax_')),
        ));

        $before = $files->exists($application) ? $files->read($application) : '';

        if (($key = $lock->violation($application, $before, $raw)) !== null) {
            $refused[] = $lock->message($application, $key);
        }

        return response()->json([
            'checks' => $checks,
            // What a save of this text would be refused for; empty means it saves.
            'refused' => $refused,
            'saveable' => $refused === [],
        ]);
    }

    /**
     * Replace the file, then do what the framework needs for the change to take effect (a cached
     * Laravel config and a running Node service both ignore a saved `.env` until told).
     */
    public function update(
        SaveEnvironmentRequest $request,
        Application $application,
        ApplicationEnvironment $files,
        EnvironmentInspector $inspector,
        EnvironmentKeyLock $lock,
        FrameworkDetector $detector,
        ActivityLogger $activity,
    ): JsonResponse {
        $raw = (string) $request->validated('raw');
        $framework = $detector->detect($application);

        // Syntax errors are refused; warnings are not (a duplicate key or debug flag is the user's call).
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

        if (($key = $lock->violation($application, $before, $raw)) !== null) {
            throw ValidationException::withMessages([
                'raw' => [$lock->message($application, $key)],
            ]);
        }

        $backup = $files->write($application, $raw, $request->safetyCopy());

        $applied = $this->apply($application, $framework, $detector);
        $restarted = $this->restart($application, $request->boolean('restart'));

        // Key names only: the values are secrets and the activity log has more readers than the file.
        $activity->log('application.environment_updated', $application, [
            'name' => $application->name,
            'keys' => implode(', ', $this->changedKeys($before, $raw)) ?: '—',
            // The file holding what this change replaced, so the history can undo this row.
            // Null on a first save.
            'backup' => $backup,
            // False when turned off, so the line says "(without a safety copy)".
            'safety_copy' => $request->safetyCopy(),
        ]);

        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application->fresh())->resolve(),
            'applied' => $applied,
            'restarted' => $restarted,
        ]);
    }

    private function refuseLockedKeyChange(Application $application, ApplicationEnvironment $files, EnvironmentKeyLock $lock, string $name): void
    {
        try {
            $backup = $files->readBackup($application, $name);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['backup' => [__('errors/application.unknown_backup')]]);
        }

        // Missing: restore() below answers that.
        if ($backup === null) {
            return;
        }

        $current = $files->exists($application) ? $files->read($application) : '';

        if (($key = $lock->violation($application, $current, $backup)) !== null) {
            throw ValidationException::withMessages([
                'backup' => [$lock->message($application, $key, backup: true)],
            ]);
        }
    }

    /**
     * Who changed this file, and when. Its own endpoint rather than a field on {@see show()}, so
     * loading the editor does not pay for a log query and a directory listing.
     */
    public function history(Request $request, Application $application, EnvironmentHistory $history): JsonResponse
    {
        $paginator = $history->for($application, (int) $request->input('per_page', 20));

        return response()->json([
            'history' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * What one logged change did, variable by variable.
     *
     * Guarded on `manage`, unlike the history list. A view-only user sees only the current values;
     * this shows previous ones, which a `manage` user can already reach by restoring a backup.
     */
    public function diff(Application $application, ActivityLog $log, EnvironmentDiff $diff): JsonResponse
    {
        // Route binding resolves the log id globally: without this check a log from another
        // application would be rendered here, and this endpoint returns secret values.
        $belongs = $log->type === 'application'
            && $log->subject_type === $application->getMorphClass()
            && (int) $log->subject_id === (int) $application->getKey()
            && in_array($log->action, ['environment_updated', 'environment_restored'], true);

        abort_unless($belongs, 404);

        return response()->json(['diff' => $diff->for($application, $log)]);
    }

    /** Put a previous save back. */
    public function restore(
        RestoreEnvironmentRequest $request,
        Application $application,
        ApplicationEnvironment $files,
        EnvironmentKeyLock $lock,
        FrameworkDetector $detector,
        ActivityLogger $activity,
    ): JsonResponse {
        $name = (string) $request->validated('backup');

        // An older copy can undo a key change just as typed text can.
        $this->refuseLockedKeyChange($application, $files, $lock, $name);

        // A well-formed name that is no longer on disk is a stale list, not a server failure.
        try {
            $safety = $files->restore($application, $name, $request->safetyCopy());
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['backup' => [__('errors/application.unknown_backup')]]);
        }

        $this->apply($application, $detector->detect($application), $detector);
        $this->restart($application, $request->boolean('restart'));

        $activity->log('application.environment_restored', $application, [
            'name' => $application->name,
            // `restored_from` is the version put back; `backup` is what this restore replaced,
            // which makes the restore undoable from its history row.
            'restored_from' => $name,
            'backup' => $safety,
            'safety_copy' => $request->safetyCopy(),
        ]);

        return response()->json([
            'environment' => ApplicationEnvironmentResource::make($application->fresh())->resolve(),
        ]);
    }

    /**
     * Clear a cached config, when there is one. The result is reported, so a failed clear is not
     * mistaken for a save that took effect.
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
        // Compared from the raw text: the parser blanks secret values, so a DB_PASSWORD change
        // would otherwise compare null to null and go unrecorded.
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
