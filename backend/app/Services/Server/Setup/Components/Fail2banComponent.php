<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Fail2ban\Fail2banManager;

/**
 * fail2ban. Optional, and left out of the installer on purpose — it is the panel's
 * brute-force containment for SSH and site logins, which is worth having but is
 * not needed for the panel to work.
 */
class Fail2banComponent implements SetupComponent
{
    public function __construct(private Fail2banManager $fail2ban) {}

    public function key(): string
    {
        return 'fail2ban';
    }

    public function installed(): bool
    {
        return $this->fail2ban->installed();
    }

    public function recommended(): bool
    {
        return true;
    }

    public function detail(): ?string
    {
        return null;
    }

    public function action(): ?array
    {
        return ['method' => 'POST', 'endpoint' => '/api/fail2ban/install'];
    }

    public function options(): array
    {
        return [];
    }
}
