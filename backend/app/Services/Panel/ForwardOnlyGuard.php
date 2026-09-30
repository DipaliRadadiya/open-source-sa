<?php

namespace App\Services\Panel;

/**
 * The shell that stops an "update" from moving the panel backwards.
 *
 * 🔴 It used to ask the opposite question — "is the release already contained
 * in what is running?" (`merge-base --is-ancestor <tag> <current>`) — and go
 * ahead on anything but yes. On a shallow clone git cannot see far enough
 * back to answer yes, and install.sh clones with `--depth 1`, so every server
 * it installed answered no: on the Apache test box (2026-09-28), running main
 * 238 commits past v1.0.17, the update checked out v1.0.17 over it and
 * reported success. A guard that fails open is only a guard on the machine
 * it was written on.
 *
 * Now it fails closed:
 * - a shallow repository fetches its full history first (~20 MB for this
 *   project), so ancestry is a question git can answer;
 * - the tag is fetched without `--depth`, which would make it shallow again;
 * - the update goes ahead only when the running commit is an ancestor of the
 *   release and is not the release itself — a real move forward. Behind,
 *   equal, or on a history that has diverged: refused.
 *
 * The fetches run for real even in a dry run. They add objects and refs and
 * never touch the checked-out files, and without them a dry run could not
 * tell whether the release is newer at all.
 */
final class ForwardOnlyGuard
{
    /**
     * @param  string  $git  a complete `git -C <repo>` prefix, already escaped
     * @param  string  $current  the commit now running, unescaped
     */
    public static function script(string $git, string $tag, string $current): string
    {
        $commit = escapeshellarg($current);
        $ref = escapeshellarg("refs/tags/{$tag}:refs/tags/{$tag}");
        $target = escapeshellarg("{$tag}^{commit}");

        return <<<SH
            if [ "\$({$git} rev-parse --is-shallow-repository)" = true ]; then
                {$git} fetch --quiet --unshallow origin
            fi
            {$git} fetch --quiet origin {$ref}
            if [ "\$({$git} rev-parse {$target})" = {$commit} ] || ! {$git} merge-base --is-ancestor {$commit} {$target}; then
                echo "Refusing update: {$tag} is not ahead of the current commit {$current}."
                finish failed target_not_newer
                exit 1
            fi
            SH;
    }
}
