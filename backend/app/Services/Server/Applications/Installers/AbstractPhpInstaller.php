<?php

namespace App\Services\Server\Applications\Installers;

use App\Actions\Server\Cronjob\CreateCronjob;
use App\Contracts\PhpStack;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Application;
use App\Models\Cronjob;
use App\Services\Server\Applications\ApplicationConfigMutator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Php\PhpShim;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

/**
 * Shared machinery for the PHP marketplace: WordPress, Moodle, Nextcloud and the rest.
 *
 * Split from the base so a Node application does not carry a PHP stack it never uses.
 */
abstract class AbstractPhpInstaller extends AbstractSiteInstaller
{
    public function __construct(
        ServerOps $serverOps,
        ProvisionProgress $progress,
        protected PhpStack $stack,
        ApplicationConfigMutator $configMutator,
        ProcessSupervisor $supervisor,
        RuntimeOwnership $ownership,
        PhpShim $shim,
    ) {
        parent::__construct($serverOps, $progress, $configMutator, $supervisor, $ownership, $shim);
    }

    /**
     * Nearly every PHP application stores its content in a database; the ones that don't
     * (Statamic) say so.
     */
    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * Nothing to run: a PHP application is served from its directory.
     *
     * Left unimplemented on the shared base on purpose, so a Node installer that forgets it
     * fails loudly instead of never starting.
     */
    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return null;
    }

    /**
     * The command prefix an application's own CLI runs under: the interpreter plus a memory limit.
     *
     * The binary is the site's version from the stack that serves it (on OpenLiteSpeed the
     * interpreter lives in the lsws tree, not `php_binary_pattern`'s FPM path).
     *
     * The memory limit is explicit because the ambient one differs per stack: Debian's CLI ini is
     * unlimited, but lsphp uses its 128M production ini, which is too small for Mautic's container
     * compile. `ApplicationPhpSettings` cannot answer this: it is written into the FPM pool or
     * site ini, which a CLI run never reads. Uses `-d` like {@see MoodleInstaller} rather than
     * editing a php.ini.
     *
     * Returned as an array and `phpBinary()` no longer exists, so a CLI run without a limit cannot
     * be expressed.
     *
     * @return array<int, string>
     */
    protected function phpCommand(Application $application): array
    {
        $version = $this->phpVersion($application);

        return [
            $this->stack->binaryPath($version),
            '-d',
            // Not inside `server.installers`: its keys are site types, so a scalar there would
            // become a one-click app.
            'memory_limit='.(string) config('server.installer_php_memory_limit', '512M'),
        ];
    }

    /**
     * The PHP this site's installer runs under: its own version, or the server default.
     */
    protected function phpVersion(Application $application): string
    {
        return (string) ($application->php_version ?: config('server.default_php_version', '8.4'));
    }

    /**
     * Schedule the application's own background job, as the site user, on the site's PHP.
     *
     * Uses the PHP binary by absolute path (`php` resolves to the server default, not the site's)
     * and the Cronjobs screen's action, so the job shows up there. Skipped when a job with the
     * same command exists, so Retry Setup does not add a second.
     *
     * `$script` may carry arguments (`bin/console mautic:segments:update`); `$phpOptions` go
     * before `-f`.
     *
     * @param  array<int, string>  $phpOptions
     *
     * @throws ProvisioningFailedException
     */
    protected function scheduleCron(Application $application, string $documentRoot, string $script, string $expression, string $label = 'background jobs', array $phpOptions = []): void
    {
        $command = implode(' ', [$this->stack->binaryPath($this->phpVersion($application)), ...$phpOptions])
            .' -f '.$documentRoot.'/'.$script;

        if (Cronjob::query()->where('command', $command)->exists()) {
            return;
        }

        try {
            app(CreateCronjob::class)->execute([
                // Unique per job: cron job names are unique and an app may have several.
                'name' => Str::limit($application->name, 200, '').' '.$label.' #'.$application->id,
                'system_user_id' => $application->system_user_id,
                'application_id' => $application->id,
                'application_owned' => true,
                'command' => $command,
                'expression' => $expression,
            ]);
        } catch (CronjobOperationException $e) {
            throw new ProvisioningFailedException('schedule_cron', $e->reference);
        }
    }
}
