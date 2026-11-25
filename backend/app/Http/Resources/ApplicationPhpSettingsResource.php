<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\PoolManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One site's PHP settings, plus what the screen needs to render them: available versions,
 * isolation state and the memory cost.
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

        // True on LSPHP, where isolation comes from how the vhost is written, not the pool feature.
        $isolatesByDefault = app(PhpStackManager::class)->stack()->isolatesByDefault();

        return [
            'application_id' => $application->id,
            'php_version' => $application->php_version,

            // Read from disk every time (a stored list goes stale). Only versions this site type
            // runs on, filtered with the endpoint's own rule so the menu never offers what the
            // endpoint rejects. The site's current version is always kept.
            'available_versions' => $this->versionsForType($application),

            // Whether this site has a pool of its own yet; the other settings are only enforceable
            // then (a shared pool means a shared memory_limit).
            'isolated' => $application->isolated_at !== null || $isolatesByDefault,
            'isolated_at' => $application->isolated_at?->format('d-m-Y H:i:s'),
            'isolation_supported' => $pools->supported(),

            // The account this site's PHP runs as. Not inferred from `isolated_at` alone: that is
            // only set by the pool feature, while LSPHP names the site's own user as `extUser`.
            'runs_as' => $application->isolated_at !== null || $isolatesByDefault
                ? $application->systemUser?->username
                : (string) config('server.web_server_user', 'www-data'),

            // False when the pool file no longer matches what the panel would write (hand-edited),
            // so the user is told before saving. Null when it could not be checked.
            'managed' => $application->isolated_at === null
                ? true
                : $pools->managed($application, $settings),

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
                // Null = derived from pm_max_children (the usual case).
                'pm_start_servers' => $effective['pm_start_servers'],
                'pm_min_spare_servers' => $effective['pm_min_spare_servers'],
                'pm_max_spare_servers' => $effective['pm_max_spare_servers'],
                'pm_process_idle_timeout' => $effective['pm_process_idle_timeout'],
                'pm_max_spawn_rate' => $effective['pm_max_spawn_rate'],
                'open_basedir_enabled' => (bool) $effective['open_basedir_enabled'],
                'open_basedir_paths' => $effective['open_basedir_paths'],
                'disable_functions' => $effective['disable_functions'],
                'allow_url_fopen' => (bool) $effective['allow_url_fopen'],
                'php_timezone' => $effective['php_timezone'],
                'auto_prepend_file' => $effective['auto_prepend_file'],
                'additional_directives' => $effective['additional_directives'],
            ],

            // True for each field whose value is not the panel default (stored or inherited), for
            // the frontend's per-field "Reset to default". Compared with the default, not "is a
            // value stored": a new site stores open_basedir_enabled = true, which is the default.
            'overridden' => $this->overridden($settings, (string) $application->site_type),

            // Three named starting points, each with the memory it implies.
            'presets' => $this->presets(),

            'memory' => app(MemoryBudget::class)->withProposed($application, $settings),

            //  effective   — what the panel would write from the stored row.
            //  live        — what the pool file says; null when there is no pool or it sets
            //                nothing. Differs from `effective` after a hand edit or an override
            //                through additional directives (which lands later and wins).
            //  recommended — what turning it on with no additions would give.
            'open_basedir_effective' => $pools->openBasedirFor($application, $settings),
            'open_basedir_live' => $pools->liveOpenBasedir($application),
            'open_basedir_recommended' => $pools->recommendedOpenBasedir($application),

            'suggested_disable_functions' => ApplicationPhpSettings::SAFE_DISABLED_FUNCTIONS,

            // The same value as a list of presets; the flat key stays for older clients.
            'disable_functions_presets' => $this->disableFunctionPresets(),
        ];
    }

    /**
     * Starting points for `disable_functions`, safest first.
     *
     * @return array<int, array<string, string>>
     */
    private function disableFunctionPresets(): array
    {
        return array_map(fn (array $preset): array => [
            'key' => $preset['key'],
            'title' => __('php_settings.disable_functions_presets.'.$preset['key'].'.title'),
            'description' => __('php_settings.disable_functions_presets.'.$preset['key'].'.description'),
            'functions' => $preset['functions'],
        ], [
            ['key' => 'safe', 'functions' => ApplicationPhpSettings::SAFE_DISABLED_FUNCTIONS],
            ['key' => 'strict', 'functions' => ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS],
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function overridden(ApplicationPhpSettings $settings, string $siteType): array
    {
        $defaults = ApplicationPhpSettings::defaults();
        $normal = fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

        $flags = [];

        foreach (array_keys($defaults) as $key) {
            $stored = $settings->getAttribute($key);

            $flags[$key] = $stored !== null && $stored !== '' && $normal($stored) !== $normal($defaults[$key]);
        }

        // A new site is provisioned with its own list, which the generic default (null) never
        // equals. Its default is the list recorded at provisioning (not today's preset, which may
        // have moved), else the preset for its type. Compared as a set: order and spacing are not
        // a change.
        $functions = fn (mixed $value): array => collect(explode(',', (string) $value))
            ->map(fn (string $name): string => trim($name))->filter()->unique()->sort()->values()->all();

        $provisioned = filled($settings->provisioned_disable_functions)
            ? $settings->provisioned_disable_functions
            : ApplicationPhpSettings::strictDisabledFunctionsFor($siteType);

        if ($flags['disable_functions'] && $functions($settings->disable_functions) === $functions($provisioned)) {
            $flags['disable_functions'] = false;
        }

        return $flags;
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

    /**
     * @return array<int, string>
     */
    private function versionsForType(Application $application): array
    {
        $versions = app(PhpStackManager::class)->stack()->versions();
        $type = app(SiteTypeManager::class)->find((string) $application->site_type);
        $range = $type?->supportedPhpRangeFor($application);

        if ($type === null || $range === null) {
            return $versions;
        }

        $rule = new SupportedPhpVersion($range['min'] ?? null, $range['max'] ?? null, $type->name());

        return array_values(array_filter($versions, function (string $version) use ($rule, $application): bool {
            if ($version === $application->php_version) {
                return true;
            }

            $allowed = true;
            $rule->validate('php_version', $version, function () use (&$allowed): void {
                $allowed = false;
            });

            return $allowed;
        }));
    }
}
