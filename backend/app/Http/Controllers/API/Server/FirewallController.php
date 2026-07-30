<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Firewall\CreateFirewallRule;
use App\Actions\Server\Firewall\DeleteFirewallRule;
use App\Actions\Server\Firewall\ToggleFirewall;
use App\Actions\Server\Firewall\UpdateFirewallRule;
use App\Contracts\Firewall;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Server\Firewall\ToggleFirewallRequest;
use App\Http\Requests\Server\Firewall\UpdateFirewallRuleRequest;
use App\Http\Resources\FirewallRuleResource;
use App\Models\FirewallRule;
use App\Services\Server\Firewall\ListeningPorts;
use App\Services\Server\Firewall\RiskyPorts;
use App\Support\FirewallPresets;
use App\Support\SshPort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirewallController extends Controller
{
    /**
     * Live firewall status (read from UFW) + the stored rules.
     */
    public function index(
        Request $request,
        Firewall $firewall,
        ListeningPorts $listening,
        RiskyPorts $risky,
    ): JsonResponse {
        $status = $firewall->status();

        return response()->json([
            'enabled' => $status['enabled'],
            'default_policy' => $status['default_policy'],
            'rules' => FirewallRuleResource::collection(FirewallRule::query()->latest()->get())->resolve(),
            // The caller's own address, so "only my IP" is one click. Without
            // it people leave ports open to everyone rather than go and look
            // their address up.
            'your_ip' => $request->ip(),
            // Read here rather than from Settings: a user with firewall
            // access but not settings would get a 403 and fall back to 22,
            // and being wrong about the SSH port on the firewall screen is
            // how people lock themselves out.
            'ssh_port' => SshPort::current(),
            // What is actually behind the rules — a rule list alone cannot
            // tell an open port with a service on it from an open port with
            // nothing there.
            'listening' => $listening->all(),
            'risky_ports' => $risky->all(),
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

    /**
     * Edit a rule, or switch it off without deleting it. See
     * UpdateFirewallRule for why the order of operations matters.
     */
    public function update(UpdateFirewallRuleRequest $request, FirewallRule $firewallRule, UpdateFirewallRule $action): JsonResponse
    {
        return response()->json([
            'rule' => FirewallRuleResource::make($action->execute($firewallRule, $request->validated()))->resolve(),
        ]);
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
