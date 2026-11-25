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
 * Without this an adopted site shows the panel's defaults while FPM enforces what the old panel
 * wrote, and the first save would replace real limits with invented ones.
 *
 * Only values the pool states explicitly are imported: a limit it did not set comes from PHP's
 * own ini, and inventing one would turn a server default into a stored decision.
 */
class PhpSettingsDiscoverer implements Discoverable
{
    /**
     * Pool directive => settings column.
     *
     * `disable_functions` and `open_basedir` are deliberately absent: PoolManager adopts
     * open_basedir, and disable_functions is a security decision this could silently relax.
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
        // The settings belong to a site, so applications run first.
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
            // A row already here is a decision made in the panel; a migrated pool must not overwrite it.
            if ($application->phpSettings !== null) {
                continue;
            }

            $path = $this->pools->livePoolPath($application);

            if ($path === null) {
                continue;
            }

            $values = $this->parse($application, $path);

            if ($values === []) {
                // A pool that sets no limits is common; nothing to import is not a problem.
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
     * Both `php_admin_value` (what the panel writes) and `php_value` (hand-written pools) are read.
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

            // Last match wins, the way FPM resolves a repeated key.
            if (preg_match_all($pattern, $contents, $matches) && $matches[1] !== []) {
                $values[$column] = trim((string) end($matches[1]));
            }
        }

        return $values;
    }
}
