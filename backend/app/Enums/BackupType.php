<?php

namespace App\Enums;

enum BackupType: string
{
    case Filesystem = 'filesystem';
    case Database = 'database';
    case Full = 'full';
}
