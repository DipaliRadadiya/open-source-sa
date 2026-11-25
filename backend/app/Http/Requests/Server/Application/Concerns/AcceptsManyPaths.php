<?php

namespace App\Http\Requests\Server\Application\Concerns;

use App\Rules\SafeRelativePath;
use App\Services\Server\Applications\FileBrowser;

/**
 * Lets one endpoint take either a single `path` or a `paths[]` selection.
 *
 * One endpoint per operation rather than a second "bulk" route; `path` stays accepted for
 * clients written against the original shape.
 */
trait AcceptsManyPaths
{
    /**
     * The rules both shapes share. Every entry is validated individually, so an array cannot
     * carry an unchecked path.
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
     * The selection, always a de-duplicated list: a path twice would be counted twice and, for a
     * move, fail against the first one's own arrival.
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
     * A single path keeps its original semantics (a missing file is a 404); only a selection gets
     * per-path reporting, because only a selection can be partly right.
     */
    public function isBulk(): bool
    {
        return is_array($this->validated('paths'));
    }
}
