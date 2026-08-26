<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = 'dop_v1…e774';
$id = 595018956;

$resp = Http::withToken($token)->get("https://api.digitalocean.com/v2/droplets/$id");
$d = $resp->json('droplet');
echo "Status: {$d['status']}\n";
$ipv4 = collect($d['networks']['v4'])->firstWhere('type', '==', 'public');
echo "IPv4: " . ($ipv4['ip_address'] ?? 'not assigned') . "\n";
echo "Networks: " . json_encode($d['networks']) . "\n";
