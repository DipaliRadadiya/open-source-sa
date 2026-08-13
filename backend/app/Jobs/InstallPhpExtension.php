<?php

namespace App\Jobs;

use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\PhpExtensionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * apt install of a single PHP extension package.
 *
 * Unique per version+extension: apt takes a lock, so a second run for the same
 * package would wait for the first and then repeat its work.
 */
class InstallPhpExtension implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version, public string $extension, public ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return "php-ext-{$this->version}-{$this->extension}";
    }

    public function handle(PhpExtensionManager $extensions, ActivityLogger $log, InstallTracker $installs): void
    {
        $properties = ['version' => $this->version, 'extension' => $this->extension];

        $row = $installs->current('php', $this->version, $this->extension);
        $progress = $row ? new InstallProgress($row) : null;

        try {
            $extensions->install(
                $this->version,
                $this->extension,
                $progress === null ? null : function (string $chunk) use ($progress) {
                    // On a step change only — apt emits hundreds of chunks and
                    // a write each would cost more than the install.
                    if ($progress->push($chunk)) {
                        $progress->persist();
                    }
                },
            );
        } catch (RuntimeInstallException $e) {
            // Flushed before the row is marked failed, so the output that
            // explains the failure is there when the screen reads it.
            $progress?->persist();
            $installs->fail('php', $this->version, $this->extension, $e->reason, $e->reference);
            $log->log('php.extension_install_failed', null, [...$properties, 'reason' => $e->reason], actor: $this->actor());

            throw $e;
        } catch (Throwable $e) {
            $progress?->persist();
            $installs->fail('php', $this->version, $this->extension, 'unknown');
            $log->log('php.extension_install_failed', null, $properties, actor: $this->actor());

            throw $e;
        }

        $installs->succeed('php', $this->version, $this->extension);
        $log->log('php.extension_enabled', null, $properties, actor: $this->actor());
    }

    /**
     * Timeout or a killed worker — the row must not outlive the process that
     * was meant to finish it.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon('php', $this->version, $this->extension);
    }
}
