<?php

namespace App\Enums;

enum BackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
}
