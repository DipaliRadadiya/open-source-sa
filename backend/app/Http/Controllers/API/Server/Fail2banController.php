<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\InstallStatus;
use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Fail2ban\BanIpRequest;
use App\Http\Requests\Server\Fail2ban\IgnoreIpRequest;
use App\Http\Requests\Server\Fail2ban\UpdateFail2banRequest;
use App\Jobs\InstallFail2ban;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Support\IpAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

class Fail2banController extends Controller
{
    /**
     * Everything the screen needs in one call: whether fail2ban is even here,
     * the settings we manage, the jails, and who is currently banned.
     */
    public function index(Request $request, Fail2banManager $fail2ban, InstallTracker $installs): JsonResponse
    {
        $installed = $fail2ban->installed();

        // `installed` cannot tell "installing" from "failed" from "never asked" (all false).
        $install = $installs->versions(InstallFail2ban::RUNTIME)->get(InstallFail2ban::VERSION);

        return response()->json([
            'fail2ban' => [
                'installed' => $installed,
                'running' => $installed && $fail2ban->running(),
                'version' => $fail2ban->version(),
                // Null once installed: the row is deleted on success.
                'install' => $install === null ? null : [
                    'status' => $install->status->value,
                    'queued' => $install->isQueued(),
                    // A classified code, never raw apt output (it names paths and cannot be translated).
                    'reason' => $install->reason,
                    'reason_title' => $install->reason === null
                        ? null
                        : __('runtime.fail2ban_install_failed.'.$install->reason),
                    'reference' => $install->reference,
                    'started_at' => $install->started_at?->format('d-m-Y H:i:s'),
                    'finished_at' => $install->finished_at?->format('d-m-Y H:i:s'),
                ],
                // The caller's address, so the UI can offer to ignore it before enabling the SSH jail.
                'your_ip' => $request->ip(),
                'settings' => $installed ? $fail2ban->settings() : null,
                'jails' => $installed ? $fail2ban->jails() : [],
                'banned' => $installed ? $fail2ban->banned() : [],
                // Served so the frontend need not keep its own copy of the accepted list.
                'bantime_presets' => array_map(fn (array $preset) => [
                    ...$preset,
                    'label' => __("fail2ban.bantime.{$preset['key']}"),
                ], (array) config('server.fail2ban.bantime_presets', [])),
            ],
        ]);
    }

    /**
     * Install fail2ban (manage). Queued: apt is too slow to hold a request open.
     */
    public function install(Fail2banManager $fail2ban, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        if ($fail2ban->installed()) {
            return response()->json(['message' => __('errors/fail2ban.already_installed')], 422);
        }

        // One at a time: a reload mid-install shows the Install button again.
        if ($installs->versions(InstallFail2ban::RUNTIME)->get(InstallFail2ban::VERSION)?->status === InstallStatus::Installing) {
            return response()->json(['message' => __('errors/fail2ban.already_installing')], 409);
        }

        // Before dispatch, so the install is visible between this 202 and the worker starting.
        $installs->start(InstallFail2ban::RUNTIME, InstallFail2ban::VERSION);

        InstallFail2ban::dispatch(Auth::id());
        $log->log('fail2ban.install_started');

        return response()->json(['message' => __('fail2ban.install_started')], 202);
    }

    /**
     * Replace the managed settings, ignore list and jail toggles (manage).
     *
     * One endpoint because the values live in a single file rewritten as a whole.
     */
    public function update(UpdateFail2banRequest $request, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        if (! $fail2ban->installed()) {
            throw Fail2banException::notInstalled();
        }

        $data = $request->validated();
        $ignoreIps = $data['ignore_ips'] ?? $fail2ban->ignoreIps();
        $jails = $this->jailStates($data['jails'] ?? [], $fail2ban);

        $this->guardLockout($jails, $ignoreIps, $request->ip(), (bool) ($data['acknowledged'] ?? false), $fail2ban);

        $this->locked(fn () => $fail2ban->write($data, $ignoreIps, $jails));

        $log->log('fail2ban.settings_updated', null, [
            'jails' => implode(', ', array_keys(array_filter($jails))) ?: '—',
        ]);

        return response()->json(['fail2ban' => [
            'settings' => $fail2ban->settings(),
            'jails' => $fail2ban->jails(),
        ]]);
    }

    /**
     * Add one address or range to the ignore list (manage).
     *
     * `PUT /fail2ban` replaces the whole list, so concurrent edits lose one. This changes one entry
     * against the list on disk, under the same lock as every other write.
     */
    public function addIgnoreIp(IgnoreIpRequest $request, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $ip = (string) $request->validated('ip');

        $this->locked(function () use ($ip, $fail2ban): void {
            $this->guardInstalled($fail2ban);
            $current = $fail2ban->ignoreIps();

            if (in_array($ip, $current, true)) {
                throw Fail2banException::ignoreIpExists();
            }

            // The same ceiling as the whole-list form.
            if (count($current) >= 100) {
                throw Fail2banException::ignoreListFull();
            }

            $fail2ban->write($fail2ban->settings(), [...$current, $ip], $this->jailStates([], $fail2ban));
        });

        $log->log('fail2ban.ignore_ip_added', null, ['ip' => $ip]);

        return $this->settingsResponse($fail2ban);
    }

    /**
     * Remove one entry from the ignore list (manage).
     *
     * Removing your own address while an SSH jail runs risks a lockout (see `guardLockout`), so it
     * needs acknowledgement unless another entry still covers you.
     */
    public function removeIgnoreIp(IgnoreIpRequest $request, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $ip = (string) $request->validated('ip');
        $acknowledged = (bool) $request->validated('acknowledged', false);
        $caller = IpAddress::canonical((string) $request->ip());

        $this->locked(function () use ($ip, $acknowledged, $caller, $fail2ban): void {
            $this->guardInstalled($fail2ban);
            $current = $fail2ban->ignoreIps();

            if (! in_array($ip, $current, true)) {
                throw Fail2banException::ignoreIpMissing();
            }

            $remaining = array_values(array_diff($current, [$ip]));

            if (! $acknowledged
                && IpUtils::checkIp($caller, [$ip])
                && ! IpUtils::checkIp($caller, [...Fail2banManager::ALWAYS_IGNORED, ...$remaining])
                && $this->sshJailActive($fail2ban)) {
                throw Fail2banException::lockoutRisk();
            }

            $fail2ban->write($fail2ban->settings(), $remaining, $this->jailStates([], $fail2ban));
        });

        $log->log('fail2ban.ignore_ip_removed', null, ['ip' => $ip]);

        return $this->settingsResponse($fail2ban);
    }

    /**
     * Ban an address by hand (manage).
     */
    public function ban(BanIpRequest $request, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $ip = (string) $request->validated('ip');
        $jail = (string) $request->validated('jail');

        // Never ban the machine's own address. Separate from the list below: `ignoreIps()` holds
        // only the user's entries (always-ignored ones are subtracted), so it would not cover
        // loopback. On `recidive` (every port) it would cut the server off from MariaDB and Redis.
        if ($fail2ban->isOwnAddress($ip)) {
            return response()->json(['message' => __('errors/fail2ban.ip_own_address')], 422);
        }

        // The caller's own address: banning it loses the panel too, and the unban runs through it.
        if ($ip === IpAddress::canonical((string) $request->ip())) {
            return response()->json(['message' => __('errors/fail2ban.ip_your_address')], 422);
        }

        // An address the user listed as trusted is refused by policy (a ban would hold). Matched as
        // addresses and ranges, not strings: the list may hold a CIDR.
        $ignored = array_map(IpAddress::canonical(...), $fail2ban->ignoreIps());

        if ($ignored !== [] && IpUtils::checkIp($ip, $ignored)) {
            return response()->json(['message' => __('errors/fail2ban.ip_ignored')], 422);
        }

        $fail2ban->ban($ip, $jail);
        $log->log('fail2ban.ip_banned', null, ['ip' => $ip, 'jail' => $jail]);

        return response()->json(['ban' => ['ip' => $ip, 'jail' => $jail]]);
    }

    /**
     * Release an address (manage). Without `?jail=`, from every jail holding it.
     */
    public function unban(Request $request, string $ip, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            abort(404);
        }

        $released = $fail2ban->unban($ip, $request->query('jail') ? (string) $request->query('jail') : null);

        $log->log('fail2ban.ip_unbanned', null, ['ip' => $ip, 'jail' => implode(', ', $released)]);

        return response()->json(['unbanned' => ['ip' => $ip, 'jails' => $released]]);
    }

    /**
     * Release every banned address (manage), e.g. an office or VPN range banned by mistake.
     */
    public function unbanAll(Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $released = $fail2ban->unbanAll();

        if ($released === []) {
            throw Fail2banException::notBanned();
        }

        $log->log('fail2ban.all_unbanned', null, ['count' => count($released)]);

        return response()->json(['unbanned' => ['ips' => $released]]);
    }

    /**
     * Every write of jail.local, one at a time: the file is read and rewritten whole.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function locked(callable $write): mixed
    {
        return Cache::lock('fail2ban:jail-local', 60)->block(15, $write);
    }

    private function guardInstalled(Fail2banManager $fail2ban): void
    {
        if (! $fail2ban->installed()) {
            throw Fail2banException::notInstalled();
        }
    }

    private function sshJailActive(Fail2banManager $fail2ban): bool
    {
        $risky = array_column(array_filter(
            (array) config('server.fail2ban.jails', []),
            fn (array $jail) => $jail['lockout_risk'] ?? false,
        ), 'name');

        return array_intersect($risky, $fail2ban->activeJails()) !== [];
    }

    private function settingsResponse(Fail2banManager $fail2ban): JsonResponse
    {
        return response()->json(['fail2ban' => [
            'settings' => $fail2ban->settings(),
            'jails' => $fail2ban->jails(),
        ]]);
    }

    /**
     * The full enabled/disabled map, so a partial request does not turn off unmentioned jails.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, bool>
     */
    private function jailStates(array $submitted, Fail2banManager $fail2ban): array
    {
        $active = $fail2ban->activeJails();
        $states = [];

        foreach ((array) config('server.fail2ban.jails', []) as $jail) {
            $states[$jail['name']] = array_key_exists($jail['name'], $submitted)
                ? (bool) $submitted[$jail['name']]
                : in_array($jail['name'], $active, true);
        }

        return $states;
    }

    /**
     * Turning on an SSH-watching jail can lock the operator out. Allowed only if their address is
     * on the ignore list or they explicitly accept the risk.
     *
     * @param  array<string, bool>  $jails
     * @param  array<int, string>  $ignoreIps
     */
    private function guardLockout(array $jails, array $ignoreIps, ?string $callerIp, bool $acknowledged, Fail2banManager $fail2ban): void
    {
        $risky = array_column(array_filter(
            (array) config('server.fail2ban.jails', []),
            fn (array $jail) => $jail['lockout_risk'] ?? false,
        ), 'name');

        $turningOn = array_filter($risky, fn (string $name) => ($jails[$name] ?? false)
            && ! in_array($name, $fail2ban->activeJails(), true));

        if ($turningOn === [] || $acknowledged || ($callerIp !== null && in_array($callerIp, $ignoreIps, true))) {
            return;
        }

        throw Fail2banException::lockoutRisk();
    }
}
