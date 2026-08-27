<?php

namespace App\Services\Server\Setup;

use App\Contracts\SetupComponent;
use App\Enums\InstallStatus;
use App\Services\Runtime\DatabaseInstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;

/**
 * The setup page, and — the same thing — the panel's Services page.
 *
 * One catalog with two entry points. First run wraps it in a wizard; afterwards
 * the identical list lives in the panel and anything can be installed at any time.
 * Building it twice would guarantee the two drift, and skipping something on day
 * one would then mean losing it.
 *
 * Two rules this class exists to hold:
 *
 *  1. **Percent is derived**, `done ÷ total`. The commercial panel hard-codes a
 *     number per branch (8, 3, 5, 30, 30, 30, 40, 50, 75, 95) — which can go
 *     backwards, leaves a dead gap between 50 and 75, and drifts the moment a step
 *     is added. Derived cannot do any of that.
 *  2. **Every label comes from a translation key.** The same panel builds its
 *     labels as English string literals inside the controller; with eight locales
 *     that is not an option here.
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
        $rows = array_map(fn (SetupComponent $component) => $this->describe($component), $this->components);

        $total = count($rows);
        $done = count(array_filter($rows, fn (array $row) => $row['state'] === 'installed'));

        // The one in-flight component, if any — what the progress line should name.
        $current = collect($rows)->firstWhere('state', 'installing');

        return [
            // `complete` is about the *recommended* set, not everything. Nothing
            // here is required: the installer already put the web server, PHP and
            // Node in place, so the panel works from first boot. A wizard that
            // demanded the rest would be blocking people over preferences.
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
            // The model's own method, not a second implementation: it builds the
            // sentence in the *viewer's* locale and falls back when a reason has
            // no translation, so a missing key never reaches the user as text.
            'message' => $progress?->message(),
            'retryable' => $progress?->status === InstallStatus::Failed,
            'progress' => $key === 'database' && $state !== 'installed' && $progress !== null
                ? DatabaseInstallProgress::describe($progress)
                : null,
        ];
    }

    /**
     * `installed` wins over everything: it is detected from the box, and the box
     * is the truth. A stale `installing` row for something that is now present
     * would otherwise show a spinner forever.
     */
    private function state(SetupComponent $component, mixed $progress): string
    {
        if ($component->installed()) {
            return 'installed';
        }

        // Compared against the enum, not strings: `status` is cast, so
        // `=== 'installing'` is quietly always false and every row reads pending.
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
            default => null,
        };

        // `first()` with a closure, not `firstWhere()` — the latter takes a key
        // name and a value, so handing it a closure matches nothing and every row
        // silently reads as `pending`.
        return $runtime === null
            ? null
            : $this->installs->versions($runtime)->first(fn ($row) => $row->status !== InstallStatus::Ready);
    }
}
