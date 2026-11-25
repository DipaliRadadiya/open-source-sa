<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Pours a container site's archived volumes back into their volumes.
 *
 * The containers are stopped first (unlike a backup, which copies a live volume): a container
 * that kept writing during a restore would corrupt the incoming copy and lose its own writes.
 *
 * Written through a helper container, not to the host mountpoint (unlike `ArchiveVolumes`, which
 * only reads): a volume's contents and permissions belong to Docker, and untarring into
 * `/var/lib/docker/volumes/...` from the host leaves data the container cannot use.
 *
 * It refuses to restore over a volume something else is using. The site's own containers are
 * stopped by then, so anything still attached is not expecting its data to be replaced.
 */
class RestoreVolumes implements RestoreStep
{
    public function __construct(
        private ServerOps $serverOps,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function key(): string
    {
        return 'restore_volumes';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return $context->wantsVolumes();
    }

    public function run(RestoreContext $context): void
    {
        $staging = $context->stagingDirectory;

        if ($staging === null || ! is_dir($staging.'/volumes')) {
            // Fail rather than skip: continuing would report success for having done nothing.
            throw new RuntimeException('this backup contains no volumes to restore');
        }

        $tars = glob($staging.'/volumes/*.tar') ?: [];

        if ($tars === []) {
            throw new RuntimeException('this backup contains no volumes to restore');
        }

        // Use `documentRoot()`, not the `document_root` column: the column is empty for a
        // container site, so the stop would find no compose file and stop nothing.
        $documentRoot = $this->provisioner->documentRoot($context->application);

        // Stop before anything is written. Stopping an already-stopped site is not an error.
        $this->containers->stop($context->application, $documentRoot);

        // Set before the writes so cleanup knows it owes a start (`RestartProcess::cleanup()`
        // reads the same flag).
        $context->processStopped = true;

        foreach ($tars as $tar) {
            $this->pour($context, $tar);
        }

        // Deliberately not restarted here: `RestartProcess` runs last. Starting earlier would
        // bring the site up on the old compose file before `SwapFiles` replaces it.
    }

    /**
     * Replace one volume's contents with the archived copy.
     *
     * The volume is emptied first, because extracting over existing contents merges and would keep
     * files the backup never had. Empty and extract are one container run, so there is no window
     * with an empty volume.
     */
    private function pour(RestoreContext $context, string $tar): void
    {
        $name = basename($tar, '.tar');

        $this->refuseIfInUse($context, $name);

        $result = $this->serverOps->run([
            'docker', 'run', '--rm',
            '-v', $name.':/volume',
            '-v', dirname($tar).':/backup:ro',
            (string) config('server.backups.volume_helper_image', 'busybox:stable'),
            'sh', '-c',
            // `find -delete`, not `rm -rf /volume/*`, which leaves dotfiles behind.
            'set -e; cd /volume; find . -mindepth 1 -delete; tar -xf /backup/'.basename($tar).' -C /volume',
        ], [
            'feature' => 'backup',
            'op' => 'restore_volume',
            'application' => $context->application->id,
            'volume' => $name,
        ], timeout: (int) config('server.backups.job_timeout', 21600));

        if ($result->failed()) {
            throw new RuntimeException("could not restore the volume {$name}");
        }
    }

    /**
     * A volume still attached to a running container is not ours to replace.
     *
     * Asked of Docker, not the panel's rows: a pasted compose file can mount a volume into a
     * service the panel never recorded.
     */
    private function refuseIfInUse(RestoreContext $context, string $name): void
    {
        $result = $this->serverOps->run([
            'docker', 'ps', '--filter', 'volume='.$name, '--format', '{{.Names}}',
        ], [
            'feature' => 'backup',
            'op' => 'restore_volume_in_use',
            'application' => $context->application->id,
            'volume' => $name,
        ], timeout: 30);

        // A failed `docker ps` is not "nothing is using it": that is how a restore overwrites live
        // data.
        if (! $result->answered) {
            throw new RuntimeException("could not check whether the volume {$name} is in use");
        }

        $holders = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));

        if ($holders !== []) {
            throw new RuntimeException(
                "the volume {$name} is still in use by ".implode(', ', $holders)
            );
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // Nothing on disk to remove (the runner removes the staging directory), but this step
        // stops the containers, so a failed restore must start them again. `SwapFiles` and
        // `RestartProcess` have the same guard; the `processStopped` flag makes all three safe.
        if (! $failed || ! $context->processStopped) {
            return;
        }

        $this->containers->start(
            $context->application,
            $this->provisioner->documentRoot($context->application),
        );

        $context->processStopped = false;
    }
}
