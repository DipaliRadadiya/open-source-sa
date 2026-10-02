<?php

namespace App\Http\Resources;

use App\Models\AddonRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued addon command. `result` is the addon's own answer once finished —
 * its success document, or the same error body a synchronous call returns —
 * and `http_status` the status that call would have had.
 *
 * The argument list is not echoed back: it can carry a search-replace term
 * Central already knows and nobody else needs in a poll response.
 *
 * @mixin AddonRun
 */
class AddonRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'addon' => $this->addon,
            'command' => $this->command,
            'status' => $this->status,
            'http_status' => $this->http_status,
            'result' => $this->result,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
