<?php

namespace App\Http\Controllers\API\Server;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Http\Requests\Server\Setting\RebootScheduleRequest;
use App\Http\Requests\Server\Setting\RebootServerRequest;
use App\Http\Requests\Server\Setting\RedisSettingsRequest;
use App\Http\Requests\Server\Setting\SecuritySettingsRequest;
use App\Http\Requests\Server\Setting\SwapSettingsRequest;
use App\Http\Requests\Server\Setting\UpdateSettingsRequest;
use App\Jobs\InstallSecurityUpdates;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;
use App\Services\Server\Settings\RebootScheduleSettings;
use App\Services\Server\Settings\RedisSettings;
use App\Services\Server\Settings\SecurityUpdateRunner;
use App\Services\Server\Settings\SecurityUpdateTracker;
use App\Services\Server\Settings\SettingChangeLog;
use App\Services\Server\Settings\SettingsManager;
use App\Support\ActivityChanges;
use App\Support\ProbeCache;
use App\Support\ServerTimezone;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class SettingController extends Controller
{
    /**
     * All available setting groups with their current values, plus who last changed each one.
     *
     * `last_changed` sits beside `settings`, not inside each group: the group maps are live OS
     * state echoed verbatim by every `PUT`.
     */
    public function index(SettingsManager $settings, SettingChangeLog $changes): JsonResponse
    {
        // Every group asks the server (sshd, apt, swap, timedatectl), which is slow; forgotten on
        // every change made here. Keyed per language: the payload carries diffForHumans() text.
        $values = ProbeCache::remember('settings:'.app()->getLocale(), fn (): array => $settings->all());

        return response()->json([
            'settings' => $values,
            // Cast to object: an empty array would encode as `[]`, not the `{}` map clients expect.
            'last_changed' => (object) $changes->forGroups(array_keys($values)),
        ]);
    }

    public function updateGeneral(GeneralSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('general', $request, $settings, $log);
    }

    public function updateSecurity(SecuritySettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('security', $request, $settings, $log);
    }

    public function updateUpdates(UpdateSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('updates', $request, $settings, $log);
    }

    public function updateSwap(SwapSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('swap', $request, $settings, $log);
    }

    /**
     * Memory settings apply immediately; a password change is applied after the response, because
     * it replaces the credential the panel is using, hence `202`.
     */
    public function updateRedis(RedisSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        $response = $this->save('redis', $request, $settings, $log);

        $group = $settings->find('redis');

        if ($group instanceof RedisSettings && $group->passwordChangePending) {
            return response()->json([
                'message' => __('setting.redis.password_applying'),
            ], 202);
        }

        return $response;
    }

    /**
     * The frequencies the reboot schedule accepts, with localized labels.
     *
     * A closed list on purpose: this restarts the machine, so no free-form cron expression.
     */
    public function rebootSchedulePresets(): JsonResponse
    {
        return response()->json([
            'frequencies' => array_map(fn (string $frequency) => [
                'value' => $frequency,
                'label' => __("setting.reboot_schedule.frequency.{$frequency}"),
            ], RebootScheduleSettings::FREQUENCIES),
            // 0–23 in the server's own timezone, which is what cron uses.
            'hours' => array_map(fn (int $hour) => [
                'value' => $hour,
                'label' => sprintf('%02d:00', $hour),
            ], range(0, 23)),
            'days_of_week' => array_map(fn (int $day) => [
                'value' => $day,
                'label' => __("setting.reboot_schedule.day.{$day}"),
            ], range(0, 6)),
        ]);
    }

    public function updateRebootSchedule(RebootScheduleRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        $group = $settings->find('reboot_schedule');
        $before = $group->read();
        $group->apply($request->validated());
        ProbeCache::flush();

        $values = $group->read();

        // Its own verb: this arranges for the server to restart on its own.
        $log->log('setting.reboot_schedule_updated', null, [
            'enabled' => $values['enabled'] ? 'yes' : 'no',
            'frequency' => $values['frequency'],
            'hour' => sprintf('%02d:00', $values['hour']),
            'changes' => ActivityChanges::between($before, $values, ['enabled', 'frequency', 'hour', 'day_of_week', 'day_of_month']),
        ]);

        return response()->json(['reboot_schedule' => $values]);
    }

    /**
     * Install the waiting security updates now. `202 Accepted`.
     *
     * The row is written before the job is dispatched (as `InstallTracker` does), so the run is
     * visible between the 202 and the worker picking it up.
     */
    public function runSecurityUpdates(
        SecurityUpdateRunner $updates,
        SecurityUpdateTracker $runs,
        ActivityLogger $log,
    ): JsonResponse {
        ProbeCache::flush();

        // Checked before queueing, so a missing package is reported now, not as a failed job later.
        if (! $updates->available()) {
            return response()->json(['message' => __('errors/setting.security_updates_unavailable')], 422);
        }

        $run = $runs->start(Auth::id());

        // Null means one is already open. 409, not 422: the request is fine, the server state refuses it.
        if ($run === null) {
            return response()->json([
                'message' => __('errors/setting.security_updates_in_progress'),
                'security_update' => $runs->inFlight()?->toProgress(withOutput: true),
            ], 409);
        }

        InstallSecurityUpdates::dispatch($run->getKey(), Auth::id());

        $log->log('setting.security_updates_started', null, []);

        return response()->json(['security_update' => $run->toProgress(withOutput: true)], 202);
    }

    /**
     * How the current or last security update is going.
     *
     * Readable with `setting` view, but the captured output needs `manage`: apt output can carry
     * conffile diffs, debconf answers and mirror URLs.
     */
    public function securityUpdateStatus(SecurityUpdateTracker $runs): JsonResponse
    {
        $run = $runs->latest();

        return response()->json([
            'security_update' => $run?->toProgress(
                withOutput: Auth::user()?->canManage('setting') ?? false,
            ),
        ]);
    }

    /**
     * Schedule a server reboot (guarded — `setting` manage). `202 Accepted`.
     */
    public function reboot(RebootServerRequest $request, ServerOps $ops, ActivityLogger $log): JsonResponse
    {
        ProbeCache::flush();

        $delay = (int) ($request->validated()['delay_minutes'] ?? 0);
        $when = $delay > 0 ? "+{$delay}" : 'now';

        $result = $ops->run(['shutdown', '-r', $when], ['feature' => 'setting', 'group' => 'reboot', 'op' => 'reboot']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }

        $log->log('setting.reboot_requested', null, ['when' => $when]);

        return response()->json([
            'reboot' => [
                'scheduled' => true,
                'when' => $when,
                // The absolute moment from the server's own clock (`shutdown` obeys it; a client
                // clock may have drifted), in the server's timezone like the reboot schedule.
                // `at_iso` carries the offset.
                ...$this->rebootMoment(now()->addMinutes($delay)),
                // Remaining seconds computed here, so the client only ticks and does not subtract clocks.
                'seconds_remaining' => $delay * 60,
                'delay_minutes' => $delay,
            ],
        ], 202);
    }

    /**
     * Whether a restart is already scheduled, and for when.
     *
     * Read from systemd's `/run/systemd/shutdown/scheduled` (USEC = wall-clock microsecond it
     * fires) rather than remembered, since a reboot can be scheduled from a shell.
     */
    public function rebootStatus(): JsonResponse
    {
        $path = (string) config('server.reboot.scheduled_file', '/run/systemd/shutdown/scheduled');

        // Read directly, not through ServerOps: the directory is world readable, and sudo would
        // make a page-load endpoint fail on servers with an outdated grant.
        if (! is_file($path)) {
            return response()->json(['reboot' => ['scheduled' => false, 'at' => null, 'at_iso' => null, 'timezone' => ServerTimezone::get(), 'seconds_remaining' => null]]);
        }

        preg_match('/^USEC=(\d+)/m', (string) @file_get_contents($path), $matches);

        // Microseconds since the epoch, per systemd's own format.
        $at = isset($matches[1])
            ? Carbon::createFromTimestamp((int) ($matches[1] / 1_000_000))
            : null;

        return response()->json([
            'reboot' => [
                'scheduled' => true,
                ...($at === null ? ['at' => null, 'at_iso' => null, 'timezone' => ServerTimezone::get()] : $this->rebootMoment($at)),
                // Rounded up (a 15-minute delay measures 899.4 s and would open at 14:59); floored at
                // zero because the file lingers briefly after the deadline.
                'seconds_remaining' => $at ? (int) max(0, ceil(now()->diffInSeconds($at, false))) : null,
            ],
        ]);
    }

    /**
     * Call off a scheduled restart (`shutdown -c`).
     *
     * Cancelling when none is scheduled is not an error.
     */
    public function cancelReboot(ServerOps $ops, ActivityLogger $log): JsonResponse
    {
        ProbeCache::flush();

        $result = $ops->run(['shutdown', '-c'], ['feature' => 'setting', 'group' => 'reboot', 'op' => 'reboot_cancel']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }

        $log->log('setting.reboot_cancelled', null, []);

        return response()->json(['reboot' => ['scheduled' => false, 'at' => null, 'seconds_remaining' => null]]);
    }

    /**
     * Per settings group, the values its activity entry compares before and after a save, as
     * `read()` names them (display values where `read()` has one).
     */
    private const LOGGED_FIELDS = [
        'general' => ['hostname', 'timezone', 'ntp'],
        'security' => ['port', 'permit_root_login', 'password_authentication'],
        'updates' => ['security_updates_enabled', 'auto_reboot', 'reboot_time', 'reboot_with_users'],
        'swap' => ['enabled', 'size_human'],
        'redis' => ['maxmemory', 'maxmemory_policy'],
    ];

    /**
     * A group's values as the activity log shows them (Redis bytes as "256 MB", 0 as none).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function readable(string $key, array $values): array
    {
        if ($key === 'redis' && is_numeric($values['maxmemory'] ?? null)) {
            $bytes = (int) $values['maxmemory'];
            $values['maxmemory'] = $bytes > 0 ? Number::fileSize($bytes) : null;
        }

        return $values;
    }

    private function save(string $key, FormRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        $group = $settings->find($key);

        if (! $group instanceof SettingGroup || ! $group->available()) {
            abort(404, __('errors/setting.group_unavailable'));
        }

        $validated = $request->validated();
        $before = $group->read();

        $group->apply($validated);
        ProbeCache::flush();

        $after = $group->read();

        // Read back from the server on both sides, so a resent unchanged value is not a change.
        $changes = ActivityChanges::between($this->readable($key, $before), $this->readable($key, $after), self::LOGGED_FIELDS[$key] ?? []);

        // A password is never logged, only that it changed or went.
        if ($key === 'redis' && filled($validated['password'] ?? null)) {
            $changes[] = ActivityChanges::secret('password');
        } elseif ($key === 'redis' && ($validated['remove_password'] ?? false)) {
            $changes[] = ActivityChanges::secret('password', removed: true);
        }

        $log->log('setting.updated', null, ['group' => $key, 'changes' => $changes]);

        return response()->json([$key => $after]);
    }

    /**
     * A reboot time in the server's own timezone, with the offset.
     *
     * @return array{at: string, at_iso: string, timezone: string}
     */
    private function rebootMoment(CarbonInterface $moment): array
    {
        $zone = ServerTimezone::get();
        $local = $moment->copy()->setTimezone($zone);

        return [
            'at' => $local->format('d-m-Y H:i:s'),
            'at_iso' => $local->toIso8601String(),
            'timezone' => $zone,
        ];
    }
}
