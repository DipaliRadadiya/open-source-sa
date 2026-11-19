<?php

namespace App\Http\Resources;

use App\Models\Database;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One database row of the backup overview (RC-23): a database that is not part
 * of a site, whether it has a schedule of its own, and how its last backup
 * went. The same shape as {@see ApplicationBackupResource}, with the
 * database's name and engine where a site has its name and domain.
 *
 * @mixin Database
 */
class DatabaseBackupOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'database_id' => $this->id,
            'database_name' => $this->name,
            'database_engine' => $this->engine,
            // unprotected | paused | failing | protected (BackupCoverage). Only
            // present on the coverage list, which selects it with the row.
            'state' => $this->when(isset($this->backup_state), fn () => $this->backup_state),
            'backup_target' => $this->backupTarget === null
                ? null
                : BackupTargetResource::make($this->backupTarget)->resolve(),
            // The newest run of this row's own target, never a safety copy —
            // the run `state` is decided by (BackupTarget::latestRun).
            'last_backup' => $this->backupTarget?->latestRun === null
                ? null
                : BackupResource::make($this->backupTarget->latestRun)->resolve(),
        ];
    }
}
