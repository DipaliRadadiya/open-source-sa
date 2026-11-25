<?php

namespace App\Services\Panel;

/**
 * The shell that stops an "update" from moving the panel backwards.
 *
 * It fails closed: on a shallow clone (install.sh uses `--depth 1`) git cannot answer
 * ancestry questions, so a guard that proceeds unless told "already contained" lets an update
 * install an older release over a newer checkout.
 * - a shallow repository fetches its full history first (~20 MB);
 * - the tag is fetched without `--depth`, which would make it shallow again;
 * - the update goes ahead only when the running commit is an ancestor of the release and is
 *   not the release itself. Behind, equal, or diverged: refused.
 *
 * The fetches run for real even in a dry run: they add objects and refs, never touch the
 * checked-out files, and a dry run cannot tell whether the release is newer without them.
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
