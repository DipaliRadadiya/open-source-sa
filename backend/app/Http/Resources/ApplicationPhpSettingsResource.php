<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\PoolManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One site's PHP settings, plus everything the screen needs to render them
 * honestly: which versions exist, whether the site is isolated yet, and what
 * the numbers cost in memory.
 *
 * @property Application $resource
 */
class ApplicationPhpSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->resource;
        $settings = $application->phpSettings ?? new ApplicationPhpSettings;
        $pools = app(PoolManager::class);

        $effective = $settings->effective();

        return [
            'application_id' => $application->id,
            'php_version' => $application->php_version,

            // Read from disk every time. A stored list is wrong the moment
            // someone installs or removes a version.
            'available_versions' => app(PhpStackManager::class)->stack()->versions(),

            // Whether this site has a pool of its own yet. Everything else on
            // this screen is only enforceable once it does — a shared pool
            // means a shared memory_limit.
            'isolated' => $application->isolated_at !== null,
            'isolated_at' => $application->isolated_at?->format('d-m-Y H:i:s'),
            'isolation_supported' => $pools->supported(),
            'runs_as' => $application->isolated_at !== null
                ? $application->systemUser?->username
                : (string) config('server.web_server_user', 'www-data'),

            // False when the pool file no longer matches what the panel would
            // write — someone edited it by hand. Said before they press save,
            // not after their changes have gone.
            'managed' => $application->isolated_at === null
                || $pools->managed($application, $settings),

            'settings' => [
                'memory_limit' => $effective['memory_limit'],
                'upload_max_filesize' => $effective['upload_max_filesize'],
                'post_max_size' => $effective['post_max_size'],
                'max_execution_time' => (int) $effective['max_execution_time'],
                'max_input_time' => (int) $effective['max_input_time'],
                'max_input_vars' => (int) $effective['max_input_vars'],
                'session_gc_maxlifetime' => (int) $effective['session_gc_maxlifetime'],
                'pm_type' => $effective['pm_type'],
                'pm_max_children' => (int) $effective['pm_max_children'],
                'pm_max_requests' => (int) $effective['pm_max_requests'],
                'open_basedir_enabled' => (bool) $effective['open_basedir_enabled'],
                'disable_functions' => $effective['disable_functions'],
                'allow_url_fopen' => (bool) $effective['allow_url_fopen'],
                'php_timezone' => $effective['php_timezone'],
                'auto_prepend_file' => $effective['auto_prepend_file'],
                'additional_directives' => $effective['additional_directives'],
            ],

            // True for each field the user has explicitly set — false means the
            // value is inherited from the panel defaults. Lets the frontend render
            // a "Reset to default" button per field rather than guessing.
            'overridden' => [
                'memory_limit' => $settings->getAttribute('memory_limit') !== null,
                'upload_max_filesize' => $settings->getAttribute('upload_max_filesize') !== null,
                'post_max_size' => $settings->getAttribute('post_max_size') !== null,
                'max_execution_time' => $settings->getAttribute('max_execution_time') !== null,
                'max_input_time' => $settings->getAttribute('max_input_time') !== null,
                'max_input_vars' => $settings->getAttribute('max_input_vars') !== null,
                'session_gc_maxlifetime' => $settings->getAttribute('session_gc_maxlifetime') !== null,
                'pm_type' => $settings->getAttribute('pm_type') !== null,
                'pm_max_children' => $settings->getAttribute('pm_max_children') !== null,
                'pm_max_requests' => $settings->getAttribute('pm_max_requests') !== null,
                'open_basedir_enabled' => $settings->getAttribute('open_basedir_enabled') !== null,
                'disable_functions' => $settings->getAttribute('disable_functions') !== null,
                'allow_url_fopen' => $settings->getAttribute('allow_url_fopen') !== null,
                'php_timezone' => $settings->getAttribute('php_timezone') !== null,
                'auto_prepend_file' => $settings->getAttribute('auto_prepend_file') !== null,
                'additional_directives' => $settings->getAttribute('additional_directives') !== null,
            ],

            // Three named starting points instead of asking anyone to reason
            // about worker counts, each carrying the memory it implies.
            'presets' => $this->presets(),

            'memory' => app(MemoryBudget::class)->withProposed($application, $settings),

            'suggested_disable_functions' => ApplicationPhpSettings::SAFE_DISABLED_FUNCTIONS,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presets(): array
    {
        return array_map(fn (array $preset): array => [
            'key' => $preset['key'],
            'title' => __('php_settings.presets.'.$preset['key'].'.title'),
            'description' => __('php_settings.presets.'.$preset['key'].'.description'),
            'pm_type' => $preset['pm_type'],
            'pm_max_children' => $preset['pm_max_children'],
        ], [
            ['key' => 'low', 'pm_type' => 'ondemand', 'pm_max_children' => 2],
            ['key' => 'balanced', 'pm_type' => 'ondemand', 'pm_max_children' => 6],
            ['key' => 'high', 'pm_type' => 'dynamic', 'pm_max_children' => 12],
        ]);
    }
}
