<?php

use App\Http\Controllers\API\Server\FirewallController;
use Illuminate\Support\Facades\Route;

// Firewall (server panel). View gated by `firewall` (view), mutations by
// `firewall` (manage). Backed by UFW; DB is the record.

// Static route before the {firewallRule} binding.
Route::get('/firewall/presets', [FirewallController::class, 'presets'])->middleware('permission:firewall');

Route::get('/firewall', [FirewallController::class, 'index'])->middleware('permission:firewall');
Route::get('/firewall/rules', [FirewallController::class, 'rules'])->middleware('permission:firewall');
Route::post('/firewall/rules', [FirewallController::class, 'store'])->middleware('permission:firewall,manage');
Route::put('/firewall/rules/{firewallRule}', [FirewallController::class, 'update'])->middleware('permission:firewall,manage');
Route::delete('/firewall/rules/{firewallRule}', [FirewallController::class, 'destroy'])->middleware('permission:firewall,manage');
// Rules added outside the panel (FW-08): take one under management, or remove it.
Route::post('/firewall/unmanaged/adopt', [FirewallController::class, 'adoptUnmanaged'])->middleware('permission:firewall,manage');
Route::delete('/firewall/unmanaged', [FirewallController::class, 'destroyUnmanaged'])->middleware('permission:firewall,manage');
Route::put('/firewall/toggle', [FirewallController::class, 'toggle'])->middleware('permission:firewall,manage');
