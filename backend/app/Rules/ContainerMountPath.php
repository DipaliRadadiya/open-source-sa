<?php

namespace App\Rules;

use App\Services\Server\Docker\Images\CreateDefaults;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path inside a container that a volume may be mounted at.
 *
 * Three refusals, and the first is the one that matters.
 *
 *  - **The site mount, or anything under it.** That is where the generated
 *    compose file bind-mounts the site's own directory — `/app` for sites created
 *    before 2026-10-07, {@see SITE_MOUNT} for every site since, so the rule is
 *    given the path of the site it is validating. A volume mounted there SHADOWS
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
    /**
     * Where a NEW simple-mode site sees its own directory.
     *
     * A top-level directory of the panel's own, rather than `/srv/site` or
     * anything under a conventional root: File Browser serves `/srv` to its
     * users and Caddy works from it, so a site mounted there would put the
     * site's `.env` in front of whoever uses the app. No image ships content
     * at `/panel-site` — a registry survey of 45 popular images found none.
     */
    public const SITE_MOUNT = '/panel-site';

    /**
     * Where sites created before 2026-10-07 see it, and still do.
     *
     * Also where roughly a quarter of popular images keep their program, which
     * is why it stopped being the default: an empty `public_html` bound over it
     * hid changedetection.io, Gotify, Actual and every linuxserver.io image.
     * Kept for those sites so their compose file never changes underneath them.
     */
    public const LEGACY_SITE_MOUNT = '/app';

    /**
     * The characters a mount path may be written in, and nothing else.
     *
     * A positive list, because the value is written into a YAML file: a newline
     * in it ends the `volumes:` entry and starts whatever key comes next —
     * `privileged: true`, or a bind of `/` — and the generated file never passes
     * through `ComposeValidator`. Refusing newlines alone would leave the next
     * YAML-significant character (`#`, `:`, `{`) for somebody to find. `/D`
     * because `$` alone accepts one trailing newline.
     *
     * Image-declared paths go through this too ({@see CreateDefaults::volumes()}),
     * so a registry cannot write the file either.
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

        // Trailing slashes normalised first, or `/app/` walks past a comparison
        // against `/app` while meaning exactly the same directory.
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
