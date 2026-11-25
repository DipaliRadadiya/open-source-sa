<?php

namespace App\Rules;

use App\Services\Server\Docker\Images\CreateDefaults;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path inside a container that a volume may be mounted at.
 *
 * Three refusals, the first being the one that matters:
 *
 *  - **The site mount, or anything under it.** The generated compose file bind-mounts the site's
 *    directory at `/app` (sites created before the change) or {@see SITE_MOUNT}, so the rule is
 *    given the path of the site it validates. A volume there SHADOWS the site: its files stay on
 *    the host but the container serves an empty volume, which looks like deleted files.
 *  - **`/`.** Mounting over the filesystem root replaces the image; the container does not start.
 *  - **A path with no depth, or a directory the image needs to boot** (`/etc`, `/bin`, `/usr`,
 *    `/lib`, `/sbin`, `/proc`, `/sys`, `/dev`): the container ends up with no shell, libc or
 *    configuration.
 *
 * Not a general "is this safe" rule: this is a path inside the container, so it cannot reach the
 * host the way a bind SOURCE could. The danger is a broken site, not a broken box.
 */
class ContainerMountPath implements ValidationRule
{
    /**
     * Where a NEW simple-mode site sees its own directory.
     *
     * A top-level directory of the panel's own, not under `/srv`: File Browser serves `/srv` to
     * its users, so a site mounted there would expose its `.env`. No popular image ships content
     * at `/panel-site`.
     */
    public const SITE_MOUNT = '/panel-site';

    /**
     * Where older sites see their directory, and still do.
     *
     * No longer the default: many popular images keep their program at `/app`, and an empty
     * `public_html` bound over it hid the program. Kept so existing compose files do not change.
     */
    public const LEGACY_SITE_MOUNT = '/app';

    /**
     * The characters a mount path may be written in, and nothing else.
     *
     * A positive list, because the value is written into a YAML file that never passes through
     * `ComposeValidator`: a newline would end the `volumes:` entry and start another key
     * (`privileged: true`, a bind of `/`), and `#`, `:`, `{` are YAML-significant too. `/D`
     * because `$` alone accepts one trailing newline.
     *
     * Image-declared paths go through this too ({@see CreateDefaults::volumes()}).
     */
    public const PATTERN = '/^\/[A-Za-z0-9._\/-]*$/D';

    private readonly string $siteMount;

    /**
     * @param  string|null  $siteMount  The site mount of the application being
     *                                  edited; null for a site being created.
     */
    public function __construct(?string $siteMount = null)
    {
        $this->siteMount = rtrim($siteMount ?: self::SITE_MOUNT, '/');
    }

    /** An empty volume over any of these is a container that cannot boot. */
    private const RESERVED = ['/etc', '/bin', '/sbin', '/usr', '/lib', '/lib64', '/proc', '/sys', '/dev', '/boot', '/run'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('validation.docker_mount_characters'));

            return;
        }

        // Normalise trailing slashes first, or `/app/` walks past a comparison against `/app`.
        $path = rtrim((string) $value, '/');

        if ($path === '') {
            $fail(__('validation.docker_mount_root'));

            return;
        }

        if ($path === $this->siteMount || str_starts_with($path.'/', $this->siteMount.'/')) {
            $fail(__('validation.docker_mount_site_root', ['path' => $this->siteMount]));

            return;
        }

        foreach (self::RESERVED as $reserved) {
            if ($path === $reserved) {
                $fail(__('validation.docker_mount_reserved', ['path' => $reserved]));

                return;
            }
        }
    }
}
