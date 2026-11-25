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
 * One application's environment file, in the three shapes a screen needs: the raw text for the
 * editor, the parsed pairs, and the checks that judge it.
 *
 * All three come from a single read, so they cannot disagree if someone saves in between.
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
        $path = $files->path($application);

        // The file sits where the framework reads it, inside a directory the web server serves
        // by path. Reported, not relocated: moving it would break the application.
        // Not exposed when any path part below the web root is a dotfile or dot-directory:
        // every vhost refuses those.
        $root = $application->documentRoot().'/';
        $exposed = $exists
            && $application->servesFiles()
            && str_starts_with($path, $root)
            && ! collect(explode('/', substr($path, strlen($root))))
                ->contains(fn (string $part) => str_starts_with($part, '.'));

        return [
            'exists' => $exists,
            'path' => $path,
            'exposed' => $exposed,

            'framework' => $framework,
            'framework_title' => __('environment.frameworks.'.$framework),

            // The two ways a save can appear to do nothing, so the button can say what it will do.
            'requires_restart' => app(ProcessSupervisor::class)->runs($application),
            'requires_apply' => $detector->requiresApply($application, $framework),

            // The whole file only for someone who may edit it: a view-only role must not read
            // secrets like APP_KEY. `variables` and `checks` carry no secret values.
            'raw' => $request->user()?->canManage('app_environment') ? $raw : null,
            'variables' => $inspector->variables($raw),
            'checks' => $exists ? $inspector->checks($raw, $framework, $exposed) : [],

            'backups' => $files->backups($application),
        ];
    }
}
