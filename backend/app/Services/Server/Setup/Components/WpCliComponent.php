<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\WpCli\WpCli;

/**
 * wp-cli. The first WordPress install fetches it anyway; this row is for the
 * health check, which reports it missing and sends the user here (bug #4).
 *
 * Not recommended: `complete` is about the recommended set, and adding a row
 * to it would mark every existing server's setup unfinished overnight.
 */
class WpCliComponent implements SetupComponent
{
    public function __construct(private WpCli $wpCli) {}

    public function key(): string
    {
        return 'wp_cli';
    }

    public function installed(): bool
    {
        return $this->wpCli->installed();
    }

    public function recommended(): bool
    {
        return false;
    }

    public function detail(): ?string
    {
        return null;
    }

    public function action(): ?array
    {
        return ['method' => 'POST', 'endpoint' => '/api/wp-cli/install'];
    }

    public function options(): array
    {
        return [];
    }
}
