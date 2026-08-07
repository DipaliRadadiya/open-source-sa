<?php

namespace App\Enums;

enum CloneStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('clone.status.pending'),
            self::Running => __('clone.status.running'),
            self::Completed => __('clone.status.completed'),
            self::Failed => __('clone.status.failed'),
        };
    }
}
