<?php

namespace App\Enums;

enum RestoreStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return __('backup.restore_status.'.$this->value);
    }
}
