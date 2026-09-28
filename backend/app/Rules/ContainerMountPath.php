<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path inside a container that a volume may be mounted at.
 *
 * Three refusals, and the first is the one that matters.
 *
 *  - **`/app`, or anything containing it.** That is where the generated compose
 *    file bind-mounts the site's own directory. A volume mounted there SHADOWS
 *    it: the site's files are still on the host, still in the backup, and
 *    completely invisible to the running container — which serves an empty
 *    volume instead. It looks exactly like the files were deleted, and the
 *    obvious response to that is to restore a backup over the top, which
 *    changes nothing because nothing was lost. Refused rather than warned
 *    about.
 *  - **`/`.** Mounting over the root of the filesystem replaces the image. The
 *    container does not start, and nothing explains why.
 *  - **A path with no depth, or one of the directories the image needs to boot.**
 *    `/etc`, `/bin`, `/usr`, `/lib`, `/sbin`, `/proc`, `/sys`, `/dev` — an empty
 *    volume over any of these is a container with no shell, no libc or no
 *    configuration. Docker permits every one of them; the panel has no business
 *    offering it as a form field.
 *
 * Deliberately not a general "is this safe" rule. It is a path inside the
 * container, so it cannot reach the host the way a bind SOURCE could — the
 * danger is a broken site, not a broken box, and the list above is what breaks
 * one in a way nobody can diagnose from the outside.
 */
class ContainerMountPath implements ValidationRule
{
    /** The site's own directory, from `compose.blade.php`. */
    public const SITE_MOUNT = '/app';

    /** An empty volume over any of these is a container that cannot boot. */
    private const RESERVED = ['/etc', '/bin', '/sbin', '/usr', '/lib', '/lib64', '/proc', '/sys', '/dev', '/boot', '/run'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        // Trailing slashes normalised first, or `/app/` walks past a comparison
        // against `/app` while meaning exactly the same directory.
        $path = rtrim((string) $value, '/');

        if ($path === '') {
            $fail(__('validation.docker_mount_root'));

            return;
        }

        if ($path === self::SITE_MOUNT || str_starts_with($path.'/', self::SITE_MOUNT.'/')) {
            $fail(__('validation.docker_mount_site_root', ['path' => self::SITE_MOUNT]));

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
