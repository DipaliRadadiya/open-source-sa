<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function finished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
