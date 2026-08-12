<?php

namespace App\Http\Controllers\API\Server;

use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Fail2ban\BanIpRequest;
use App\Http\Requests\Server\Fail2ban\UpdateFail2banRequest;
use App\Jobs\InstallFail2ban;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Fail2ban\Fail2banManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Fail2banController extends Controller
{
    /**
     * Everything the screen needs in one call: whether fail2ban is even here,
     * the settings we manage, the jails, and who is currently banned.
     */
    public function index(Request $request, Fail2banManager $fail2ban, InstallTracker $installs): JsonResponse
    {
        $installed = $fail2ban->installed();

        // `installed` alone cannot tell "installing" from "failed" from "never
        // asked" — it is derived from the package being on disk, so all three
        // read as false. apt is allowed ten minutes here, which is a long time
        // to show someone a boolean that has not moved.
        $install = $installs->versions(InstallFail2ban::RUNTIME)->get(InstallFail2ban::VERSION);

        return response()->json([
            'fail2ban' => [
                'installed' => $installed,
                'running' => $installed && $fail2ban->running(),
                'version' => $fail2ban->version(),
                // Null once it is on disk: the row is deleted on success, and
                // `installed` is then the honest answer.
                'install' => $install === null ? null : [
                    'status' => $install->status->value,
                    // A classified code, never raw apt output — it names paths
                    // and cannot be translated. Rendered in the viewer's
                    // locale by the same keys the PHP screen uses.
                    'reason' => $install->reason,
                    'reason_title' => $install->reason === null
                        ? null
                        : __('runtime.fail2ban_install_failed.'.$install->reason),
                    'reference' => $install->reference,
                    'started_at' => $install->started_at?->format('d-m-Y H:i:s'),
                    'finished_at' => $install->finished_at?->format('d-m-Y H:i:s'),
                ],
                // The caller's own address, so the UI can offer to add it to
                // the ignore list before they enable the SSH jail. Knowing it
                // is the difference between an informed click and a lockout.
                'your_ip' => $request->ip(),
                'settings' => $installed ? $fail2ban->settings() : null,
                'jails' => $installed ? $fail2ban->jails() : [],
                'banned' => $installed ? $fail2ban->banned() : [],
                // Offered by the API so the form can present "1 hour" instead
                // of 3600, without the frontend maintaining its own copy of a
                // list that has to agree with what this endpoint accepts.
                'bantime_presets' => array_map(fn (array $preset) => [
                    ...$preset,
                    'label' => __("fail2ban.bantime.{$preset['key']}"),
                ], (array) config('server.fail2ban.bantime_presets', [])),
            ],
        ]);
    }

    /**
     * Install fail2ban (manage). Queued — apt is far too slow to hold a
     * request open for.
     */
    public function install(Fail2banManager $fail2ban, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        if ($fail2ban->installed()) {
            return response()->json(['message' => __('errors/fail2ban.already_installed')], 422);
        }

        // Before dispatch, not inside the job: otherwise there is a window
        // between this 202 and a worker picking the job up where the install
        // exists and nothing can see it — the exact blindness the row removes.
        $installs->start(InstallFail2ban::RUNTIME, InstallFail2ban::VERSION);

        InstallFail2ban::dispatch(Auth::id());
        $log->log('fail2ban.install_started');

        return response()->json(['message' => __('fail2ban.install_started')], 202);
    }

    /**
     * Replace the managed settings, ignore list and jail toggles (manage).
     *
     * One endpoint rather than three: the values live in a single file that is
     * rewritten as a whole, and splitting them would invite two requests to
     * race over the same file.
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

        $fail2ban->write($data, $ignoreIps, $jails);

        $log->log('fail2ban.settings_updated', null, [
            'jails' => implode(', ', array_keys(array_filter($jails))) ?: '—',
        ]);

        return response()->json(['fail2ban' => [
            'settings' => $fail2ban->settings(),
            'jails' => $fail2ban->jails(),
        ]]);
    }

    /**
     * Ban an address by hand (manage) — for a nuisance the operator has
     * spotted before fail2ban has.
     */
    public function ban(BanIpRequest $request, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $ip = (string) $request->validated('ip');
        $jail = (string) $request->validated('jail');

        // Banning an address on the ignore list would be undone at the next
        // reload, so it is refused rather than quietly accepted.
        if (in_array($ip, $fail2ban->ignoreIps(), true)) {
            return response()->json(['message' => __('errors/fail2ban.ip_ignored')], 422);
        }

        $fail2ban->ban($ip, $jail);
        $log->log('fail2ban.ip_banned', null, ['ip' => $ip, 'jail' => $jail]);

        return response()->json(['ban' => ['ip' => $ip, 'jail' => $jail]]);
    }

    /**
     * Release an address (manage). Without `?jail=`, from every jail holding
     * it — "unban" means the address can connect again, not that it stays
     * banned by a jail the user wasn't looking at.
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
     * Release every banned address (manage).
     *
     * The case this exists for is an office or VPN range banned by mistake,
     * where the operator is locked out and clicking eleven rows individually
     * is not what the moment calls for.
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
     * The full enabled/disabled map, so a partial request doesn't silently
     * turn off the jails it failed to mention — the file is rewritten whole.
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
     * Turning on a jail that watches SSH is the one action here that can lock
     * the operator out of their own server. It is allowed — that is the point
     * of the feature — but not by accident: either their address is on the
     * ignore list, or they have said explicitly that they accept the risk.
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
