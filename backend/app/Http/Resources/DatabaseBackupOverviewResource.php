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
            'backup_target' => $this->backupTarget === null
                ? null
                : BackupTargetResource::make($this->backupTarget)->resolve(),
            'last_backup' => $this->latestBackup === null
                ? null
                : BackupResource::make($this->latestBackup)->resolve(),
        ];
    }
}
