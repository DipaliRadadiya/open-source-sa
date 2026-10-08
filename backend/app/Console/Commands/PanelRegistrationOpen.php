<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Whether the first-administrator registration is still open (FS-A3).
 *
 * Exit 0 while nobody has registered, 1 once someone has — for `install.sh`,
 * which told every re-run "anyone who reaches this address can claim the
 * administrator account" on a panel that had had one for months. Same
 * question `GET /api/basic-info` answers as `registration_open`.
 */
class PanelRegistrationOpen extends Command
{
    protected $signature = 'panel:registration-open';

    protected $description = 'Exit 0 while the first administrator account can still be claimed, 1 once it has been';

    public function handle(): int
    {
        $open = User::query()->where('is_system', false)->doesntExist();

        $this->line($open ? 'open' : 'closed');

        return $open ? self::SUCCESS : self::FAILURE;
    }
}
