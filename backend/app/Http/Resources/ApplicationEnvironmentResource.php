<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\EnvironmentInspector;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\Applications\ProcessSupervisor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One application's environment file, in the three shapes a screen needs: the
 * raw text for the editor, the parsed pairs for anything that wants a value,
 * and the checks that judge it.
 *
 * All three come from a single read. The alternative — a raw endpoint plus a
 * parsed endpoint plus a checks endpoint — reads the same file three times and
 * can return three different answers if someone saves in between.
 *
 * @property Application $resource
 */
class ApplicationEnvironmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->resource;
        $files = app(ApplicationEnvironment::class);
        $detector = app(FrameworkDetector::class);
        $inspector = app(EnvironmentInspector::class);

        $framework = $detector->detect($application);
        $exists = $files->exists($application);
        $raw = $exists ? $files->read($application) : '';

        return [
            'exists' => $exists,
            'path' => $files->path($application),

            'framework' => $framework,
            'framework_title' => __('environment.frameworks.'.$framework),

            // The two ways a save can appear to do nothing. Both are answered
            // here so the button can say what it will actually do rather than
            // the user discovering it afterwards.
            'requires_restart' => app(ProcessSupervisor::class)->runs($application),
            'requires_apply' => $detector->requiresApply($application, $framework),

            'raw' => $raw,
            'variables' => $inspector->variables($raw),
            'checks' => $exists ? $inspector->checks($raw, $framework) : [],

            'backups' => $files->backups($application),
        ];
    }
}
