<?php

namespace App\Enums;

/**
 * What a backup target captures.
 *
 * Two families on one axis, because a target has exactly one type and the
 * families never mix: a site is either served from a document root with a
 * database beside it, or it is containers with volumes.
 *
 * **Why a container site is not described with `Filesystem`.** Its document root
 * holds a compose file and nothing else — the data lives in volumes.
 * `Filesystem` there would mean "archive one YAML file" and hand someone an
 * artefact they believe is their site, which is precisely why container sites
 * had no backup option at all until now.
 *
 * **Why nothing here captures the container itself.** A container is an image
 * plus a compose declaration plus the volumes it mounts; its writable layer
 * holds only what the application wrote *outside* a volume, which is by
 * definition the data nobody chose to keep. `docker commit` would capture that
 * layer and restore a container that disagrees with its own compose file,
 * leaving the panel holding two conflicting definitions of one site. The
 * container's *definition* — image, digest, ports, networks, limits — is
 * recorded in every container backup's manifest instead: a few hundred bytes,
 * and the part a restore actually reads.
 *
 * **Why one enum rather than two booleans** ("volumes?" and "config?"): two
 * checkboxes permit neither, which is a target that captures nothing, runs
 * nightly, and succeeds forever.
 */
enum BackupType: string
{
    case Filesystem = 'filesystem';
    case Database = 'database';
    case Full = 'full';

    /** A container site's volumes, and nothing else. The data. */
    case Volumes = 'volumes';

    /** Its compose files and `.env`. Tiny — the definition before an edit. */
    case Config = 'config';

    /** Both. The default for a container site: what you rebuild from. */
    case VolumesConfig = 'volumes_config';

    /**
     * The types that describe a container site.
     *
     * @return list<self>
     */
    public static function container(): array
    {
        return [self::Volumes, self::Config, self::VolumesConfig];
    }

    /**
     * The types that describe a site served from a document root.
     *
     * @return list<self>
     */
    public static function hosted(): array
    {
        return [self::Filesystem, self::Database, self::Full];
    }

    public function isContainer(): bool
    {
        return in_array($this, self::container(), true);
    }
}
