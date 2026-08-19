<?php

namespace App\Http\Requests\Server\Backup;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteBackupsRequest extends FormRequest
{
    /**
     * How many backups one request may delete.
     *
     * Each deletion is a round trip to object storage, so an unbounded list is
     * a request that runs until the web server gives up — halfway through, with
     * no response saying which ones went. A ceiling makes the client page
     * through instead, and every page reports its own outcome.
     */
    public const MAX = 100;

    public function authorize(): bool
    {
        // The route already carries `permission:backup,manage`; this is the
        // second half of the rule, kept here so a route added later without
        // the middleware still cannot delete.
        return $this->user()?->canManage('backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `required` and `min:1`, so an empty selection is refused rather
            // than answered with a cheerful "deleted 0 backups" — the client
            // sending nothing is a bug on its side, not a no-op to absorb.
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            // Each id must exist. A stale row the user could still see in a
            // list they loaded five minutes ago is refused as a whole request
            // rather than silently skipped, so the count they are shown is the
            // count that happened.
            'ids.*' => ['integer', 'distinct', 'exists:backups,id'],
        ];
    }
}
