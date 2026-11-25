<?php

namespace App\Enums;

/**
 * What a backup target captures.
 *
 * Two families on one axis (a target has exactly one type): a site served from a document root
 * with a database beside it, or containers with volumes.
 *
 * A container site is not described with `Filesystem`: its document root holds only a compose
 * file, the data lives in volumes.
 *
 * Nothing here captures the container itself (`docker commit` would restore a container that
 * disagrees with its compose file). Its definition (image, digest, ports, networks, limits) goes
 * into every container backup's manifest instead.
 *
 * One enum rather than two booleans ("volumes?", "config?"): two checkboxes permit neither, a
 * target that captures nothing and succeeds forever.
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
