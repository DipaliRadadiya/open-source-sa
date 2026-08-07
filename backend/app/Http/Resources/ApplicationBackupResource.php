<?php

namespace App\Http\Resources;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cross-application backup overview: a site, whether it is
 * configured, and how its last backup went.
 *
 * Built from the application rather than from the backup target, because the
 * sites that matter most on that screen are the ones with no target at all.
 * `backup_target` stays nested rather than flattened for the same reason — on
 * an unprotected site every target field would be null, and a caller could
 * not tell "not configured" from "configured with nothing set".
 *
 * @mixin Application
 */
class ApplicationBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'application_id' => $this->id,
            'application_name' => $this->name,
            'application_domain' => $this->domain,
            'backup_target' => $this->backupTarget === null
                ? null
                : BackupTargetResource::make($this->backupTarget)->resolve(),
            'last_backup' => $this->latestBackup === null
                ? null
                : BackupResource::make($this->latestBackup)->resolve(),
        ];
    }
}
