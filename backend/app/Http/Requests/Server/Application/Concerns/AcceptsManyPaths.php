<?php

namespace App\Http\Requests\Server\Application\Concerns;

use App\Rules\SafeRelativePath;
use App\Services\Server\Applications\FileBrowser;

/**
 * Lets one endpoint take either a single `path` or a `paths[]` selection.
 *
 * Kept as one endpoint per operation rather than a second "bulk" route: two
 * ways to delete a file is two things to keep in step, and the single form is
 * only the selection of one. `path` remains accepted so clients written
 * against the original shape keep working.
 */
trait AcceptsManyPaths
{
    /**
     * The rules both shapes share. Every entry is validated individually —
     * an array must not become a way to get one unchecked path through.
     *
     * @return array<string, mixed>
     */
    protected function pathRules(): array
    {
        return [
            'path' => ['required_without:paths', 'string', 'max:1024', new SafeRelativePath],

            'paths' => ['required_without:path', 'array', 'min:1', 'max:'.FileBrowser::MAX_BULK_PATHS],
            'paths.*' => ['required', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    /**
     * The selection, always as a list, and always de-duplicated: the same
     * path twice would be counted twice in the result and, for a move, would
     * make the second attempt fail against the first one's own arrival.
     *
     * @return list<string>
     */
    public function selectedPaths(): array
    {
        $paths = $this->validated('paths');

        if (! is_array($paths)) {
            $paths = [$this->validated('path')];
        }

        return array_values(array_unique(array_map('strval', $paths)));
    }

    /**
     * Whether a selection was sent rather than a single path.
     *
     * The two are not the same operation. A single path keeps its original
     * semantics — a missing file is a 404, not a 200 with one entry in a
     * `failed` list — because a caller who named one file wants a status
     * code. Only a selection gets per-path reporting, because only a
     * selection can be partly right.
     */
    public function isBulk(): bool
    {
        return is_array($this->validated('paths'));
    }
}
