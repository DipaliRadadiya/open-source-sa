<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Docker\VolumeSizes;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Packs a container site's volumes, one tar per volume, for `ArchiveFiles` to
 * fold into the single uploaded artefact.
 *
 * **Why this step has to exist at all.** A container site's document root holds
 * a compose file and nothing else; everything that cannot be recreated is in
 * its volumes. Archiving the root and calling it a backup is what kept container
 * sites from having a Backups screen — an artefact someone believes is their
 * site, containing one YAML file.
 *
 * **One tar per volume, not one for all of them.** The name is how a restore
 * knows which volume to pour each tar back into, and a single tar of several
 * mountpoints loses that mapping unless it is re-derived from directory names
 * Docker does not guarantee. Per-volume also gives the manifest a size per
 * volume, which is what makes "this backup is 40GB" explainable.
 *
 * **Read from the host mountpoint, not through a helper container.** The panel
 * already walks exactly these root-owned paths to measure a container site's
 * size, so both the sudo grant and the pattern exist. A helper container would
 * make every backup depend on pulling an image, and on that image still being
 * pullable the day the backup runs. Restoring is the opposite case — nothing may
 * write into a volume from the host while Docker owns it — which is why
 * `RestoreVolumes` does use a container.
 *
 * **Crash-consistent, and it says so.** No downtime is the standing constraint,
 * so a running container keeps writing while tar reads. For a volume of files
 * that is fine. For one holding a live database it is not: the copy restores and
 * then does not work. Every volume copied this way is recorded with
 * `consistent => false`, so the manifest and the restore screen can say it
 * rather than leaving someone to find out. Logical dumps for the engines the
 * panel can introspect are the next step, and they will mark their own volumes
 * consistent.
 */
class ArchiveVolumes implements BackupStep
{
    public function __construct(
        private ServerOps $serverOps,
        private VolumeSizes $volumes,
    ) {}

    public function key(): string
    {
        return 'archive_volumes';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return $context->wantsVolumes();
    }

    public function run(BackupContext $context): void
    {
        $discovered = $this->volumes->discover($context->application());
        $selected = $this->selected($context, $discovered);

        // Recorded even when empty, and recorded as what it is. A container
        // site with no volumes keeps nothing across a rebuild, which is a real
        // answer; the manifest saying so is the difference between that and a
        // backup that silently found nothing.
        $context->manifest['volumes'] = [];
        $context->manifest['volumes_skipped'] = array_values(array_diff(
            array_keys($discovered),
            array_keys($selected),
        ));

        if ($selected === []) {
            return;
        }

        $directory = $context->workingDirectory.'/volumes';

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('could not create the volume staging directory');
        }

        // Deliberately not `track()`ed. The runner's cleanup is `@unlink()` per
        // tracked path, which cannot remove a directory and would fail silently
        // — cleanup that reads as done and is not. The runner deletes the whole
        // working directory on the next line of its own cleanup, which covers
        // this and everything in it.

        foreach ($selected as $name => $mountpoint) {
            $context->manifest['volumes'][] = $this->archive($context, $directory, $name, $mountpoint);
        }
    }

    /**
     * Tar one volume's contents, from inside the mountpoint.
     *
     * `-C <mountpoint> .` so the archive holds `./thing` rather than
     * `/var/lib/docker/volumes/<name>/_data/thing`. A restore pours these into a
     * volume whose host path is a different string on a different machine, and
     * an archive of absolute paths cannot be poured anywhere but where it came
     * from.
     *
     * @return array<string, mixed>
     */
    private function archive(BackupContext $context, string $directory, string $name, string $mountpoint): array
    {
        $file = $this->fileName($name);
        $path = $directory.'/'.$file;

        $result = $this->serverOps->run(
            ['tar', '-cf', $path, '-C', $mountpoint, '.'],
            [
                'feature' => 'backup',
                'op' => 'archive_volume',
                'application' => $context->application()->id,
                'volume' => $name,
            ],
            timeout: (int) config('server.backups.job_timeout', 21600),
        );

        // Tolerant of the one way tar is routinely non-zero: a file changed or
        // vanished while being read, which on a live volume is expected rather
        // than exceptional. An archive that was never written at all is a real
        // failure, so that is what is checked.
        if (! is_file($path)) {
            throw new RuntimeException("could not archive the volume {$name}");
        }

        return [
            'name' => $name,
            'file' => 'volumes/'.$file,
            'bytes' => (int) filesize($path),
            'strategy' => 'file_copy',
            // See the class docblock. Honest, and load-bearing: the restore
            // screen warns on it.
            'consistent' => false,
            'changed_while_reading' => $result->failed(),
        ];
    }

    /**
     * Which of the discovered volumes this target captures.
     *
     * A null or empty `volume_scope` means every volume, **including ones added
     * later**, and that is the default on purpose: a site that gains a volume
     * next month stays covered, where a list fixed at setup silently stops
     * covering it and nothing reports a gap. An explicit list is honoured as
     * given, and a name in it that no longer exists is simply absent from the
     * result rather than failing the run — a volume somebody deleted is not a
     * reason to stop backing up the rest.
     *
     * @param  array<string, string>  $discovered
     * @return array<string, string>
     */
    private function selected(BackupContext $context, array $discovered): array
    {
        $scope = $context->target->volume_scope;

        if ($scope === null || $scope === []) {
            return $discovered;
        }

        return array_intersect_key($discovered, array_flip(array_map('strval', $scope)));
    }

    /**
     * A volume name is Docker-constrained to `[a-zA-Z0-9][a-zA-Z0-9_.-]*`, so it
     * is already a safe filename. Put through a whitelist anyway, because the
     * value reaches a path and `volume_mounts` can name a volume the panel did
     * not create and therefore never validated.
     */
    private function fileName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.tar';
    }

    public function cleanup(BackupContext $context): void
    {
        // Nothing of its own: the staging directory goes with the run's working
        // directory.
    }
}
