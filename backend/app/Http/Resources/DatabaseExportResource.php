<?php

namespace App\Http\Resources;

use App\Enums\ExportStatus;
use App\Support\Bytes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatabaseExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completed = $this->status === ExportStatus::Completed;

        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            // Copied onto the row rather than read through the relation, so a
            // dump of a since-deleted database still says what it was of.
            'database' => $this->database_name,
            'engine' => $this->engine,
            'file' => $this->file,
            'status' => $this->status->value,
            'size_bytes' => $this->size_bytes,
            'size_human' => Bytes::human((int) $this->size_bytes),
            // A stable code plus the sentence built in the *viewer's* locale.
            'reason' => $this->reason,
            'message' => $this->message(),
            'reference' => $this->reference,
            // Whether the file is still there. Someone can remove one by hand,
            // and offering a download that 404s is worse than saying it's gone.
            'available' => $completed && $this->fileExists(),
            'download_url' => $completed && $this->fileExists()
                ? url('/api/databases/exports/'.$this->file)
                : null,
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
