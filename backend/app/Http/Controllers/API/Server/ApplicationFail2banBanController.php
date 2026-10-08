<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BanApplicationIpRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Support\IpAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bans in one application's own jail (FS-C45).
 *
 * The application's Fail2ban page could configure its jail and never see what
 * it caught: the list, ban and unban were server-level only, behind the
 * server `fail2ban` permission, and the server ban accepts only the server's
 * jails. These touch this application's jail and nothing else, under the
 * application's own permission. Since FB-K a site jail's ban blocks the
 * address at that site only, so none of this can reach SSH or the panel.
 */
class ApplicationFail2banBanController extends Controller
{
    public function index(Application $application, Fail2banManager $fail2ban): JsonResponse
    {
        $jail = $this->activeJail($application, $fail2ban);

        return response()->json([
            'jail' => $jail,
            'banned' => $jail === null ? [] : $fail2ban->bannedIn($jail),
        ]);
    }

    public function store(BanApplicationIpRequest $request, Application $application, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        $jail = $this->requireActiveJail($application, $fail2ban);
        $ip = IpAddress::canonical($request->ip());

        // The server's own address, as the server-level ban refuses it: the
        // site would stop answering its own cron, health checks and loopback
        // calls. And the caller's own, who would lose the site they are
        // looking at with nothing on screen to say why.
        if ($fail2ban->isOwnAddress($ip)) {
            return response()->json(['message' => __('errors/fail2ban.ip_own_address')], 422);
        }

        if ($ip === IpAddress::canonical((string) $request->getClientIp())) {
            return response()->json(['message' => __('errors/fail2ban.ip_your_address')], 422);
        }

        $fail2ban->ban($ip, $jail);
        $log->log('application.fail2ban_ip_banned', $application, ['name' => $application->name, 'ip' => $ip]);

        return response()->json(['ban' => ['ip' => $ip, 'jail' => $jail]]);
    }

    public function destroy(Request $request, Application $application, string $ip, Fail2banManager $fail2ban, ActivityLogger $log): JsonResponse
    {
        abort_unless($request->user()?->canManage('app_fail2ban') ?? false, 403);
        abort_if(filter_var($ip, FILTER_VALIDATE_IP) === false, 404);

        $jail = $this->requireActiveJail($application, $fail2ban);
        $fail2ban->unban(IpAddress::canonical($ip), $jail);

        $log->log('application.fail2ban_ip_unbanned', $application, ['name' => $application->name, 'ip' => $ip]);

        return response()->json(['unbanned' => ['ip' => $ip, 'jail' => $jail]]);
    }

    /** The application's jail when fail2ban is running it, else null. */
    private function activeJail(Application $application, Fail2banManager $fail2ban): ?string
    {
        $jail = $application->fail2ban_jail_name;

        return $jail !== null && in_array($jail, $fail2ban->activeJails(), true) ? $jail : null;
    }

    private function requireActiveJail(Application $application, Fail2banManager $fail2ban): string
    {
        $jail = $this->activeJail($application, $fail2ban);

        if ($jail === null) {
            abort(response()->json([
                'message' => __('errors/fail2ban.app_jail_not_enabled'),
                'reason' => 'jail_not_enabled',
            ], 409));
        }

        return $jail;
    }
}
