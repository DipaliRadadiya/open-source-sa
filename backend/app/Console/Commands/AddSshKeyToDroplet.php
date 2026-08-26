<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AddSshKeyToDroplet extends Command
{
    protected $signature = 'droplet:add-ssh-key
                            {droplet_id}
                            {public_key}
                            {--token= : DigitalOcean API token}';

    protected $description = 'Add an SSH public key to a DigitalOcean droplet';

    public function handle(): int
    {
        $token = $this->option('token') ?? config('services.digitalocean.token');

        if (! $token) {
            $this->error('No DO token. Pass --token= or set digitalocean.token in config.');
            return self::FAILURE;
        }

        $dropletId = $this->argument('droplet_id');
        $publicKey = $this->argument('public_key');

        $this->info("Adding SSH key to droplet $dropletId...");

        $resp = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(30)
            ->post("https://api.digitalocean.com/v2/droplets/$dropletId/actions", [
                'type' => 'add_ssh_key',
                'public_key' => $publicKey,
            ]);

        if ($resp->successful()) {
            $actionId = $resp->json('action.id');
            $this->info("Done. Action ID: $actionId");
            return 0;
        }

        $this->error("Failed: HTTP {$resp->status()} — {$resp->body()}");
        return 1;
    }
}
