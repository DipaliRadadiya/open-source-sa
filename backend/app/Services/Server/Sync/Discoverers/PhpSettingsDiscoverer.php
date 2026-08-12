<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SyncRun;
use App\Services\Server\Php\PoolManager;

/**
 * The PHP limits a migrated site is actually running under.
 *
 * Without this an adopted site shows the panel's defaults — 256M, 30 seconds —
 * while FPM is enforcing whatever the old panel wrote. The screen would be
 * describing a server it is not looking at, and the first save would quietly
 * replace real limits with invented ones.
 *
 * Only values the pool states explicitly are imported. A limit the old pool
 * did not set is one PHP was taking from its own ini, and inventing a number
 * for it here would turn a server default into a stored decision that then
 * stops following the server.
 */
class PhpSettingsDiscoverer implements Discoverable
{
    /**
     * Pool directive => settings column.
     *
     * `disable_functions` and `open_basedir` are deliberately absent:
     * open_basedir is adopted by PoolManager when the panel takes over the
     * pool, and disable_functions is a security decision this would silently
     * relax if the old pool set a shorter list than the panel's preset.
     */
    private const IMPORTABLE = [
        'memory_limit' => 'memory_limit',
        'upload_max_filesize' => 'upload_max_filesize',
        'post_max_size' => 'post_max_size',
        'max_execution_time' => 'max_execution_time',
        'max_input_time' => 'max_input_time',
        'max_input_vars' => 'max_input_vars',
        'session.gc_maxlifetime' => 'session_gc_maxlifetime',
    ];

    public function __construct(private PoolManager $pools) {}

    public function resourceType(): string
    {
        return 'php_settings';
    }

    public function dependsOn(): array
    {
        // The settings belong to a site. Before applications have run there
        // is nothing to attach them to.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        if (! $this->pools->supported()) {
            // OpenLiteSpeed runs LSPHP and has no pools to read.
            return [];
        }

        $found = [];

        $applications = Application::query()
            ->with(['systemUser', 'phpSettings'])
            ->where('serving_profile', 'php')
            ->get();

        foreach ($applications as $application) {
            // A row already here means the user has answered for this site.
            // A migrated pool must not overwrite a decision made in the panel.
            if ($application->phpSettings !== null) {
                continue;
            }

            $path = $this->pools->livePoolPath($application);

            if ($path === null) {
                continue;
            }

            $values = $this->parse($application, $path);

            if ($values === []) {
                // A pool that sets no limits at all is the common case for a
                // simple site. Nothing to import is not a problem to report.
                continue;
            }

            $found[] = [
                'key' => $application->slug ?: $application->domain,
                'label' => $application->name,
                'confidence' => 100,
                'evidence' => ['pool' => $path, 'values' => $values],
                'attributes' => ['application_id' => $application->id, 'values' => $values],
            ];
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return ApplicationPhpSettings::updateOrCreate(
            ['application_id' => $attributes['application_id']],
            $attributes['values'],
        );
    }

    /**
     * The limits this pool states, and only those.
     *
     * Both `php_admin_value` and `php_value` are read: the first is what the
     * panel writes, the second is what plenty of hand-written pools use, and
     * a site running under one of them is running under it either way.
     *
     * @return array<string, string>
     */
    private function parse(Application $application, string $path): array
    {
        $contents = $this->pools->readPool($path);

        if ($contents === null) {
            return [];
        }

        $values = [];

        foreach (self::IMPORTABLE as $directive => $column) {
            $pattern = '/^\s*php_(?:admin_)?(?:value|flag)\s*\[\s*'
                .preg_quote($directive, '/')
                .'\s*\]\s*=\s*(.+?)\s*$/mi';

            // Last match wins, the way FPM resolves a repeated key — taking
            // the first would import a value the server is not using.
            if (preg_match_all($pattern, $contents, $matches) && $matches[1] !== []) {
                $values[$column] = trim((string) end($matches[1]));
            }
        }

        return $values;
    }
}
