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
 * **The containers are stopped first, and that is not the same decision taken
 * for backups.** A backup may not cost downtime — the site is up and expected
 * to stay up — so it copies a live volume and records the copy as
 * crash-consistent. A restore is already an outage by the time it runs: the data
 * is being replaced underneath the application, and a container that kept
 * writing through that would corrupt the incoming copy *and* lose its own
 * writes. Stopping is what makes a restore deterministic.
 *
 * **Written through a helper container, not to the host mountpoint.** This is
 * the asymmetry with `ArchiveVolumes`, which reads the host path directly.
 * Reading root-owned files is something the panel already does; writing into a
 * volume is not, because its contents and permissions belong to Docker, and
 * untarring into `/var/lib/docker/volumes/...` from the host is how a volume
 * ends up full of correct data the container cannot use. Mounting the volume
 * into a throwaway container means Docker does the writing, exactly as for any
 * other write to that volume.
 *
 * **It refuses to restore over a volume something else is using.** Restoring
 * into a live volume is the one way this feature can destroy the thing it exists
 * to protect. The site's own containers are stopped by then, so its own volumes
 * are free; anything still attached belongs to something that is not expecting
 * its data to be replaced.
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
            // Said plainly rather than skipped quietly: a volumes restore that
            // finds no volumes to restore means the archive does not contain
            // what its type claims, and continuing would report success for
            // having done nothing.
            throw new RuntimeException('this backup contains no volumes to restore');
        }

        $tars = glob($staging.'/volumes/*.tar') ?: [];

        if ($tars === []) {
            throw new RuntimeException('this backup contains no volumes to restore');
        }

        // `ApplicationProvisioner::documentRoot()`, which is what every other
        // caller of the supervisor uses — `ApplyVhost`, `PullContainerImage`,
        // `UpdateContainerCompose` and the rest. The `document_root` *column* is
        // empty for a container site; the value the API shows is computed. This
        // step originally read the column, passed an empty string, and the stop
        // then found no compose file and stopped nothing — the containers stayed
        // up and the in-use guard below refused the restore, which is how the
        // mistake surfaced rather than becoming a write into a live volume.
        $documentRoot = $this->provisioner->documentRoot($context->application);

        // Stop before anything is written. `stop` on a site whose containers are
        // already down is not an error — compose answers for the project, not
        // for one container's state.
        $this->containers->stop($context->application, $documentRoot);

        foreach ($tars as $tar) {
            $this->pour($context, $tar);
        }

        // Deliberately not restarted here. `RestartProcess` runs last and owns
        // bringing a site back up for every site type. Starting the containers
        // here would start them before `SwapFiles` has put the restored compose
        // file in place, so a volumes+config restore would come up on the old
        // definition and then have it swapped underneath.
    }

    /**
     * Replace one volume's contents with the archived copy.
     *
     * Emptying first is explicit, because tar has no `--delete-first`:
     * extracting over existing contents *merges*, which would leave files the
     * backup never contained alive inside a volume that is supposed to be a
     * point-in-time copy. The empty and the extract are one container run, so
     * there is no window in which the volume is empty and something could start
     * against it.
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
            // `find . -mindepth 1 -delete` rather than `rm -rf /volume/*`, which
            // leaves dotfiles behind — and a dotfile is exactly what an
            // application checks to decide whether to re-run first-time setup.
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
     * Asked of Docker rather than of the panel's own rows, because the panel
     * does not know about containers it did not create — and a pasted compose
     * file can mount a volume into a service the panel never recorded.
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

        // A question that could not be answered is not permission to proceed.
        // Reading "nothing is using it" out of a failed `docker ps` is how a
        // restore overwrites live data.
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
        // Nothing of its own on disk — the staged tars belong to the staging
        // directory the runner removes.
    }
}
