<?php

namespace App\Http\Resources;

use App\Support\Bytes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatabaseImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            'database' => $this->database_name,
            'engine' => $this->engine,
            'file_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'size_human' => Bytes::human((int) $this->size_bytes),
            // How much of the upload has arrived — the resume point after a
            // dropped connection, and the upload half of a progress bar.
            'received_bytes' => $this->received_bytes,
            'status' => $this->status->value,
            // While running: `checking` (reading the file), `backing_up`,
            // `importing`, `rolling_back` (putting the backup back).
            'step' => $this->step,
            'reason' => $this->reason,
            // Built in the viewer's locale, and always says what happened to
            // the data: untouched, put back from the backup, or not put back.
            'message' => $this->message(),
            'line' => $this->line,
            'reference' => $this->reference,
            'restored' => $this->restored,
            // The automatic backup taken before anything was replaced; it is
            // also in the Exports list, as `purpose: import_safety`.
            'safety_export_id' => $this->safety_export_id,
            'requested_by' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ] : null),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->finished_at?->diffForHumans(),
        ];
    }
}
