<?php

namespace App\Services\Git\Webhooks;

class Ref
{
    /**
     * The branch name out of a `refs/heads/...` ref.
     *
     * Returns null for anything that is not a branch — a tag push arrives as
     * `refs/tags/v1.0`, and treating its name as a branch would deploy a tag
     * push to the branch that happened to share its name. Null for a deletion
     * too (`refs/heads/x` with a null commit is handled by the caller's branch
     * match, but a ref of `null` never matches anything).
     */
    public static function branch(?string $ref): ?string
    {
        if ($ref === null || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        $branch = substr($ref, strlen('refs/heads/'));

        return $branch === '' ? null : $branch;
    }
}
