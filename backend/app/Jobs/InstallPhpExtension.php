<?php

namespace App\Jobs;

use App\Services\ActivityLogger;
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

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version, public string $extension) {}

    public function uniqueId(): string
    {
        return "php-ext-{$this->version}-{$this->extension}";
    }

    public function handle(PhpExtensionManager $extensions, ActivityLogger $log): void
    {
        $properties = ['version' => $this->version, 'extension' => $this->extension];

        try {
            $extensions->install($this->version, $this->extension);
        } catch (Throwable $e) {
            $log->log('runtime.php_extension_install_failed', null, $properties);

            throw $e;
        }

        $log->log('runtime.php_extension_enabled', null, $properties);
    }
}
