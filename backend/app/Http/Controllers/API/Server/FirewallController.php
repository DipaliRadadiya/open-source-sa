<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Firewall\CreateFirewallRule;
use App\Actions\Server\Firewall\DeleteFirewallRule;
use App\Actions\Server\Firewall\ToggleFirewall;
use App\Actions\Server\Firewall\UpdateFirewallRule;
use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Firewall\IndexFirewallRulesRequest;
use App\Http\Requests\Server\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Server\Firewall\ToggleFirewallRequest;
use App\Http\Requests\Server\Firewall\UnmanagedFirewallRuleRequest;
use App\Http\Requests\Server\Firewall\UpdateFirewallRuleRequest;
use App\Http\Resources\FirewallRuleResource;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Firewall\ListeningPorts;
use App\Services\Server\Firewall\RiskyPorts;
use App\Services\Server\Sync\Discoverers\FirewallRuleDiscoverer;
use App\Support\FirewallPresets;
use App\Support\ListSearch;
use App\Support\ListSort;
use App\Support\SshPort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        FirewallRuleDiscoverer $discoverer,
    ): JsonResponse {
        // `status()` throws when ufw cannot be read, which is right for the guards. This is a
        // screen: failing the request would hide the rules, ports and SSH port, so an
        // undetermined state is reported as `null` and the rest still renders.
        try {
            $status = $firewall->status();
            $reference = null;
        } catch (FirewallOperationException $exception) {
            $status = ['enabled' => null, 'default_policy' => null];
            $reference = $exception->reference;
        }

        return response()->json([
            // Nullable: `null` means the state could not be read. Clients must not treat it as
            // `false`: "off" invites turning the firewall on, and this needs a server fix first.
            'enabled' => $status['enabled'],
            'default_policy' => $status['default_policy'],
            // Present only when the state could not be read.
            'status_reference' => $reference,
            // Every rule, unpaged. `?rules=0` leaves it out; paging clients should send that
            // (`GET /firewall/rules` pages them).
            'rules' => $request->query('rules') === '0'
                ? null
                : FirewallRuleResource::collection(FirewallRule::query()->latest()->get())->resolve(),
            // The caller's own address, so "only my IP" is one click.
            'your_ip' => $request->ip(),
            // Read here, not from Settings: a user without settings access would get a 403 and
            // fall back to 22, and a wrong SSH port on this screen locks people out.
            'ssh_port' => SshPort::current(),
            // What actually listens behind the rules.
            'listening' => $listening->all(),
            'risky_ports' => $risky->all(),
            // Rules ufw enforces that were added outside the panel. `adoptable` false means the
            // panel cannot hold it (LIMIT, outbound, application profile); `reason` says why.
            // Null when ufw could not be read.
            'unmanaged_rules' => $this->unmanagedRules($discoverer),
        ]);
    }

    /**
     * Take a rule added outside the panel under the panel's management.
     */
    public function adoptUnmanaged(UnmanagedFirewallRuleRequest $request, FirewallRuleDiscoverer $discoverer, ActivityLogger $activity): JsonResponse
    {
        $rule = $this->adoptOrFail($request->key(), $discoverer);
        $activity->log('firewall.rule_adopted', $rule, ['ports' => $rule->summary()]);

        return response()->json(['rule' => FirewallRuleResource::make($rule)->resolve()], 201);
    }

    /**
     * Remove a rule added outside the panel, through the same guards as any
     * other: adopted first, so the SSH-lockout and protected-rule checks see
     * it, and the row put back out again if they refuse.
     */
    public function destroyUnmanaged(UnmanagedFirewallRuleRequest $request, FirewallRuleDiscoverer $discoverer, DeleteFirewallRule $delete): JsonResponse
    {
        $rule = $this->adoptOrFail($request->key(), $discoverer);

        try {
            $delete->execute($rule);
        } catch (\Throwable $e) {
            $rule->exists && $rule->delete();

            throw $e;
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function unmanagedRules(FirewallRuleDiscoverer $discoverer): ?array
    {
        $items = $discoverer->unmanaged();

        if ($items === null) {
            return null;
        }

        return array_map(fn (array $item): array => [
            'key' => $item['key'],
            'rule' => trim(($item['evidence']['to'] ?? '').' '.($item['evidence']['action'] ?? '').' from '.($item['evidence']['from'] ?? '')),
            'adoptable' => ! isset($item['skip']),
            'reason' => isset($item['skip']) ? __('sync.reasons.'.$item['skip']) : null,
        ], $items);
    }

    private function adoptOrFail(string $key, FirewallRuleDiscoverer $discoverer): FirewallRule
    {
        $item = collect($discoverer->unmanaged() ?? [])->firstWhere('key', $key);

        if ($item === null) {
            throw ValidationException::withMessages(['key' => [__('errors/firewall.unmanaged_not_found')]]);
        }

        if (isset($item['skip'])) {
            throw ValidationException::withMessages(['key' => [__('sync.reasons.'.$item['skip'])]]);
        }

        /** @var FirewallRule */
        return $discoverer->adopt($item);
    }

    /**
     * Common-service presets for the frontend dropdown.
     */
    public function presets(DatabaseManager $databases): JsonResponse
    {
        return response()->json([
            'presets' => FirewallPresets::all(mariadb: $databases->engine('mariadb')->available()),
        ]);
    }

    /**
     * The rules on their own, paged.
     *
     * Separate from {@see index()} so turning a page does not re-run `ss`.
     */
    public function rules(IndexFirewallRulesRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        $rules = FirewallRule::query()
            // array_key_exists, not `??`: `filter[enabled]=0` asks for disabled rules.
            ->when(array_key_exists('enabled', $filter) && $filter['enabled'] !== null,
                fn ($query) => $query->where('enabled', (bool) $filter['enabled']))
            ->when($filter['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filter['origin'] ?? null, fn ($query, $origin) => $query->where('origin', $origin))
            // Grouped: the service-name match is an OR and must not escape the AND filters above.
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $this->applyRuleSearch($query, $search)
            ));

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

    /**
     * Free text across a rule's own fields, plus the service name for its port.
     *
     * A preset name resolves to its port at query time, because the seeded SSH and web rules
     * have no description. A range covering the port matches too (20:30 covers SSH).
     */
    private function applyRuleSearch(Builder $query, string $search): Builder
    {
        ListSearch::apply($query, $search, ['port_from', 'port_to', 'source_ip', 'description']);

        $port = FirewallPresets::portFor($search);

        if ($port === null) {
            return $query;
        }

        return $query
            ->orWhere('port_from', $port)
            ->orWhere(fn (Builder $query) => $query
                ->where('port_from', '<=', $port)
                ->where('port_to', '>=', $port));
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
            // Rules as they stand after the toggle: enabling seeds allow rules for SSH and the
            // panel/web ports (`ToggleFirewall::seedDefaults()`) that no client could predict.
            // Unpaginated on purpose, so a page boundary cannot hide the seeded rows.
            'rules' => FirewallRuleResource::collection(
                FirewallRule::query()->orderBy('port_from')->orderBy('id')->get()
            )->resolve(),
        ]);
    }
}
