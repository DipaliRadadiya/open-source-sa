<?php

namespace App\Exceptions\Server\Application;

use App\Models\Backup;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * FS-B10: the backup asked for before a staging push did not finish, so the
 * push was not started. Names the backup row so the screen can link to its
 * reason rather than repeat a generic sentence.
 */
class StagingBackupFailedException extends RuntimeException
{
    public function __construct(public readonly Backup $backup)
    {
        parent::__construct(__('errors/application.staging_backup_failed'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'reason' => 'backup_failed',
            'backup_id' => $this->backup->id,
            'backup_reason' => $this->backup->reason,
        ], 409);
    }
}
