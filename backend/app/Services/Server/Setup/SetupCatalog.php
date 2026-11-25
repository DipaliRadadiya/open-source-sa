<?php

namespace App\Services\Server\Setup;

use App\Contracts\SetupComponent;
use App\Enums\InstallStatus;
use App\Jobs\InstallBuildTools;
use App\Jobs\InstallFail2ban;
use App\Jobs\InstallWpCli;
use App\Services\Runtime\DatabaseInstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;

/**
 * The setup page and the panel's Services page: one catalog, two entry points.
 *
 * Percent is derived (`done ÷ total`), never hard-coded per step.
 * Every label comes from a translation key.
 */
class SetupCatalog
{
    /**
     * @param  array<int, SetupComponent>  $components
     */
    public function __construct(
        private array $components,
        private InstallTracker $installs,
        private ServerCapabilities $capabilities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Rows that do not apply to this server are left out, not just marked unrecommended:
        // the row would stay on the page with its install button.
        $rows = array_values(array_map(
            fn (SetupComponent $component) => $this->describe($component),
            array_filter($this->components, fn (SetupComponent $component) => $component->applies()),
        ));

        $total = count($rows);
        $done = count(array_filter($rows, fn (array $row) => $row['state'] === 'installed'));

        // The one in-flight component, if any — what the progress line should name.
        $current = collect($rows)->firstWhere('state', 'installing');

        return [
            // `complete` covers the recommended set only; nothing here is required.
            'complete' => collect($rows)
                ->where('recommended', true)
                ->every(fn (array $row) => $row['state'] === 'installed'),
            'status' => $current !== null ? 'installing' : 'idle',
            'percent' => $total === 0 ? 100 : (int) round($done / $total * 100),
            'key' => $current['key'] ?? null,
            'label' => $current !== null
                ? __('setup.installing', ['component' => $current['title']])
                : null,
            'stack' => $this->capabilities->current()->stack,
            'web_server' => $this->capabilities->recordedWebServer(),
            'components' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SetupComponent $component): array
    {
        $key = $component->key();
        $progress = $this->progressFor($key);
        $state = $this->state($component, $progress);

        return [
            'key' => $key,
            'title' => __("setup.components.{$key}.title"),
            'description' => __("setup.components.{$key}.description"),
            'state' => $state,
            'detail' => $component->detail(),
            'recommended' => $component->recommended(),
            'action' => $component->action(),
            'options' => $component->options(),
            'reason' => $progress?->reason,
            // Builds the sentence in the viewer's locale and falls back when a reason has no
            // translation.
            'message' => $progress?->message(),
            'retryable' => $progress?->status === InstallStatus::Failed,
            // Installing, but still waiting behind other jobs.
            'queued' => $state === 'installing' && $progress?->isQueued() === true,
            'progress' => $key === 'database' && $state !== 'installed' && $progress !== null
                ? DatabaseInstallProgress::describe($progress)
                : null,
        ];
    }

    /**
     * `installed` wins over everything: it is detected from the box, so a stale `installing` row
     * cannot show a spinner forever.
     */
    private function state(SetupComponent $component, mixed $progress): string
    {
        if ($component->installed()) {
            return 'installed';
        }

        // Compare against the enum: `status` is cast, so a string comparison is always false.
        return match ($progress?->status) {
            InstallStatus::Installing => 'installing',
            InstallStatus::Failed => 'failed',
            default => 'pending',
        };
    }

    /**
     * In-flight or failed installs, from `runtime_installs`. Only ever those two:
     * a finished install deletes its row, which is what stops this disagreeing
     * with detection.
     */
    private function progressFor(string $key): mixed
    {
        $runtime = match ($key) {
            'database' => 'database',
            'php' => 'php',
            'node' => 'node',
            'wp_cli' => InstallWpCli::RUNTIME,
            'fail2ban' => InstallFail2ban::RUNTIME,
            'build_tools' => InstallBuildTools::RUNTIME,
            default => null,
        };

        // `first()` with a closure: `firstWhere()` takes a key and a value and would match nothing.
        return $runtime === null
            ? null
            : $this->installs->versions($runtime)->first(fn ($row) => $row->status !== InstallStatus::Ready);
    }
}
