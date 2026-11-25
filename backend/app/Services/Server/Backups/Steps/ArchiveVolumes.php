<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Exceptions\NoVolumesToBackUp;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Docker\VolumeSizes;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Packs a container site's volumes, one tar per volume, for `ArchiveFiles` to fold into the
 * single uploaded artefact.
 *
 * A container site's document root holds only a compose file; everything that cannot be
 * recreated is in its volumes.
 *
 * One tar per volume: the name tells a restore which volume to pour each tar into, and the
 * manifest gets a size per volume.
 *
 * Read from the host mountpoint, not through a helper container, so a backup does not depend on
 * pulling an image (`RestoreVolumes` does use one, because writing into a Docker-owned volume
 * from the host is not safe).
 *
 * Crash-consistent only: there is no downtime, so a running container keeps writing while tar
 * reads. That is fine for files, not for a live database, so every volume is recorded with
 * `consistent => false` for the manifest and the restore screen to show.
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
        $found = $this->volumes->find($context->application());

        // Volumes only, of a site with none: tar refuses to write an empty archive, so say so
        // here. `volumes_config` still has the compose file, so it carries on. Null (Docker did
        // not answer) is not "none" and is left to fail.
        if ($found === [] && ! $context->wantsConfig()) {
            throw new NoVolumesToBackUp('the application has no volumes to back up');
        }

        $discovered = $found ?? [];
        $selected = $this->selected($context, $discovered);

        // Recorded even when empty, so "no volumes" is distinguishable from a backup that
        // silently found nothing.
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

        // Deliberately not `track()`ed: the runner's cleanup `@unlink()`s tracked paths, which
        // cannot remove a directory. It deletes the whole working directory anyway.

        foreach ($selected as $name => $mountpoint) {
            $context->manifest['volumes'][] = $this->archive($context, $directory, $name, $mountpoint);
        }
    }

    /**
     * Tar one volume's contents, from inside the mountpoint.
     *
     * `-C <mountpoint> .` keeps paths relative (`./thing`), so a restore can pour the archive into
     * a volume whose host path differs on another machine.
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

        // tar is routinely non-zero on a live volume (a file changed or vanished while being
        // read), so only a missing archive is a failure.
        if (! is_file($path)) {
            throw new RuntimeException("could not archive the volume {$name}");
        }

        return [
            'name' => $name,
            'file' => 'volumes/'.$file,
            'bytes' => (int) filesize($path),
            'strategy' => 'file_copy',
            // See the class docblock; the restore screen warns on it.
            'consistent' => false,
            'changed_while_reading' => $result->failed(),
        ];
    }

    /**
     * Which of the discovered volumes this target captures.
     *
     * A null or empty `volume_scope` means every volume, including ones added later, so a new
     * volume stays covered. An explicit list is honoured as given; a name that no longer exists is
     * dropped rather than failing the run.
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
     * Docker volume names are already filename-safe, but `volume_mounts` can name a volume the
     * panel never validated and the value reaches a path, so whitelist it anyway.
     */
    private function fileName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.tar';
    }

    public function cleanup(BackupContext $context): void
    {
        // Nothing of its own: the staging directory goes with the working directory.
    }
}
