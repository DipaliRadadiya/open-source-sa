<?php

use App\Http\Controllers\API\Server\FirewallController;
use Illuminate\Support\Facades\Route;

// Firewall (server panel). View gated by `firewall` (view), mutations by
// `firewall` (manage). Backed by UFW; DB is the record.

// Static route before the {firewallRule} binding.
Route::get('/firewall/presets', [FirewallController::class, 'presets'])->middleware('permission:firewall');

Route::get('/firewall', [FirewallController::class, 'index'])->middleware('permission:firewall');
Route::post('/firewall/rules', [FirewallController::class, 'store'])->middleware('permission:firewall,manage');
Route::delete('/firewall/rules/{firewallRule}', [FirewallController::class, 'destroy'])->middleware('permission:firewall,manage');
Route::put('/firewall/toggle', [FirewallController::class, 'toggle'])->middleware('permission:firewall,manage');
