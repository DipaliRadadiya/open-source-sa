<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Firewall\CreateFirewallRule;
use App\Actions\Server\Firewall\DeleteFirewallRule;
use App\Actions\Server\Firewall\ToggleFirewall;
use App\Contracts\Firewall;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Server\Firewall\ToggleFirewallRequest;
use App\Http\Resources\FirewallRuleResource;
use App\Models\FirewallRule;
use App\Support\FirewallPresets;
use Illuminate\Http\JsonResponse;

class FirewallController extends Controller
{
    /**
     * Live firewall status (read from UFW) + the stored rules.
     */
    public function index(Firewall $firewall): JsonResponse
    {
        $status = $firewall->status();

        return response()->json([
            'enabled' => $status['enabled'],
            'default_policy' => $status['default_policy'],
            'rules' => FirewallRuleResource::collection(FirewallRule::query()->latest()->get())->resolve(),
        ]);
    }

    /**
     * Common-service presets for the frontend dropdown.
     */
    public function presets(): JsonResponse
    {
        return response()->json([
            'presets' => FirewallPresets::all(),
        ]);
    }

    public function store(StoreFirewallRuleRequest $request, CreateFirewallRule $action): JsonResponse
    {
        $rule = $action->execute($request->validated());

        return response()->json([
            'rule' => FirewallRuleResource::make($rule)->resolve(),
        ], 201);
    }

    public function destroy(FirewallRule $firewallRule, DeleteFirewallRule $action): JsonResponse
    {
        $action->execute($firewallRule);

        return response()->json(null, 204);
    }

    public function toggle(ToggleFirewallRequest $request, ToggleFirewall $action): JsonResponse
    {
        $status = $action->execute($request->boolean('enabled'));

        return response()->json([
            'enabled' => $status['enabled'],
            'default_policy' => $status['default_policy'],
        ]);
    }
}
