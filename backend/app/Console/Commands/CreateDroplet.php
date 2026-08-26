<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CreateDroplet extends Command
{
    protected $signature = 'do:create-droplet
                            {--token= : DigitalOcean API token}
                            {--name=sv-oss-test : Droplet name}
                            {--region=ams3 : Region slug}
                            {--size=s-2vcpu-4gb : Droplet size slug}
                            {--tag= : Optional tag}';

    protected $description = 'Create a DigitalOcean droplet and print its IP';

    public function handle(): int
    {
        $token = $this->option('token') ?? config('services.digitalocean.token');

        if (! $token) {
            $this->error('No DO token provided. Pass --token= or set digitalocean.token in config.');
            return self::FAILURE;
        }

        $name = $this->option('name');
        $region = $this->option('region');
        $size = $this->option('size');
        $tag = $this->option('tag');

        $this->info("Creating droplet '$name' in $region ($size)...");

        $payload = [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => 'ubuntu-24-04-x64',
            'ssh_keys' => [],
            'backups' => false,
            'ipv6' => false,
            'monitoring' => true,
            'user_data' => $this->cloudConfig(),
        ];

        if ($tag) {
            $payload['tags'] = [$tag];
        }

        $response = Http::withToken($token, 'DigitalOcean')
            ->acceptJson()
            ->post('https://api.digitalocean.com/v2/droplets', $payload);

        if (! $response->successful()) {
            $this->error('Droplet creation failed: '.$response->body());
            return self::FAILURE;
        }

        $droplet = $response->json('droplet');
        $ip = collect($droplet['networks']['v4'])->firstWhere('type', '==', 'public')['ip_address'] ?? null;

        $this->info("Droplet created!");
        $this->line("ID:        {$droplet['id']}");
        $this->line("Name:      {$droplet['name']}");
        $this->line("Region:    {$droplet['region']['slug']}");
        $this->line("Status:    {$droplet['status']}");
        $this->line("IP:        {$ip}");
        $this->line("Created:   {$droplet['created_at']}");

        return self::SUCCESS;
    }

    private function cloudConfig(): string
    {
        // Cloud-init: install essentials, resize root, set hostname
        return base64_encode("#cloud-config
package_update: true
packages:
  - curl
  - sudo
  - git
  - unzip
runcmd:
  - hostnamectl set-hostname ".escapeshellarg($this->option('name'))."
");
    }
}
