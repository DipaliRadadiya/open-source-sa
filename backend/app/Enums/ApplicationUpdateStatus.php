<?php

namespace App\Enums;

enum ApplicationUpdateStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('app_update.status.pending'),
            self::Running => __('app_update.status.running'),
            self::Completed => __('app_update.status.completed'),
            self::Failed => __('app_update.status.failed'),
        };
    }

    /** @return array<int, self> */
    public static function active(): array
    {
        return [self::Pending, self::Running];
    }
}
