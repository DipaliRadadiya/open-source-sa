<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Firewall\CreateFirewallRule;
use App\Actions\Server\Firewall\DeleteFirewallRule;
use App\Actions\Server\Firewall\ToggleFirewall;
use App\Actions\Server\Firewall\UpdateFirewallRule;
use App\Contracts\Firewall;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Firewall\IndexFirewallRulesRequest;
use App\Http\Requests\Server\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Server\Firewall\ToggleFirewallRequest;
use App\Http\Requests\Server\Firewall\UpdateFirewallRuleRequest;
use App\Http\Resources\FirewallRuleResource;
use App\Models\FirewallRule;
use App\Services\Server\Firewall\ListeningPorts;
use App\Services\Server\Firewall\RiskyPorts;
use App\Support\FirewallPresets;
use App\Support\ListSort;
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

    /**
     * The rules on their own, paged.
     *
     * Separate from {@see index()} because that endpoint answers "what is the
     * state of this firewall" and this one answers "which rules are there".
     * Turning a page should not re-run `ss` to rebuild a list of listening
     * ports that has not changed.
     */
    public function rules(IndexFirewallRulesRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        $rules = FirewallRule::query()
            // array_key_exists, not `??` — `filter[enabled]=0` is a real
            // request for the disabled rules, and a falsy check would drop it
            // and answer with everything.
            ->when(array_key_exists('enabled', $filter) && $filter['enabled'] !== null,
                fn ($query) => $query->where('enabled', (bool) $filter['enabled']))
            ->when($filter['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filter['origin'] ?? null, fn ($query, $origin) => $query->where('origin', $origin))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn ($q) => $q
                    ->where('port_from', 'like', $like)
                    ->orWhere('port_to', 'like', $like)
                    ->orWhere('source_ip', 'like', $like)
                    ->orWhere('description', 'like', $like));
            });

        $rules = ListSort::apply($rules, $request->validated('sort'), IndexFirewallRulesRequest::SORTS)
            ->paginate($request->validated('per_page', IndexFirewallRulesRequest::PER_PAGE));

        return response()->json([
            'rules' => FirewallRuleResource::collection($rules->items())->resolve(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
                'last_page' => $rules->lastPage(),
            ],
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
