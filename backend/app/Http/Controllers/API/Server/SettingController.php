<?php

namespace App\Http\Controllers\API\Server;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Http\Requests\Server\Setting\MysqlSettingsRequest;
use App\Http\Requests\Server\Setting\RebootScheduleRequest;
use App\Http\Requests\Server\Setting\RebootServerRequest;
use App\Http\Requests\Server\Setting\RedisSettingsRequest;
use App\Http\Requests\Server\Setting\SecuritySettingsRequest;
use App\Http\Requests\Server\Setting\SwapSettingsRequest;
use App\Http\Requests\Server\Setting\UpdateSettingsRequest;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;
use App\Services\Server\Settings\RebootScheduleSettings;
use App\Services\Server\Settings\RedisSettings;
use App\Services\Server\Settings\SettingChangeLog;
use App\Services\Server\Settings\SettingsManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class SettingController extends Controller
{
    /**
     * All available setting groups with their current values, plus who last
     * changed each one.
     *
     * `last_changed` is a sibling of `settings` rather than a field inside each
     * group: the group maps are live OS state and are echoed verbatim by every
     * `PUT`, and an actor is neither of those things.
     */
    public function index(SettingsManager $settings, SettingChangeLog $changes): JsonResponse
    {
        $values = $settings->all();

        return response()->json([
            'settings' => $values,
            // Cast, not left as an array: json_encode() cannot tell an empty
            // associative array from an empty list and emits `[]` either way,
            // which a client expecting an object (a map keyed by group) then
            // rejects. A box where nothing has been changed yet — every fresh
            // install — hits this on the very first request.
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
     * The response carries the value the *server* adopted, not the one that
     * was asked for. MySQL and MariaDB silently cap `max_connections` against
     * `open_files_limit`, so `save()` re-reads the group afterwards and the
     * form shows what actually happened rather than echoing the request back.
     */
    public function updateMysql(MysqlSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('mysql', $request, $settings, $log);
    }

    /**
     * Memory settings apply immediately; a password change is applied after
     * this response is sent, because the credential the panel is currently
     * using is the one being replaced. `202` says so rather than returning a
     * body that claims a password is set before it is.
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
     * Served rather than hardcoded in the frontend, same as the cronjob
     * schedule presets — and it is a closed list on purpose: this restarts
     * the machine, so there is no free-form cron expression to get wrong.
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
        $group->apply($request->validated());

        $values = $group->read();

        // Its own verb rather than a generic "settings updated": this arranges
        // for the server to restart on its own, which is not what saving a
        // form usually means.
        $log->log('setting.reboot_schedule_updated', null, [
            'enabled' => $values['enabled'] ? 'yes' : 'no',
            'frequency' => $values['frequency'],
            'hour' => sprintf('%02d:00', $values['hour']),
        ]);

        return response()->json(['reboot_schedule' => $values]);
    }

    /**
     * Schedule a server reboot (guarded — `setting` manage). `202 Accepted`.
     */
    public function reboot(RebootServerRequest $request, ServerOps $ops, ActivityLogger $log): JsonResponse
    {
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
                // The absolute moment, computed from the server's own clock.
                // `when` is `+60`, and a client turning that into a time with
                // its own clock is wrong by however far the two have drifted —
                // on the one number where being wrong means someone schedules
                // a restart for the wrong hour. `shutdown` obeys this clock,
                // so this clock is the one that answers.
                'at' => now()->addMinutes($delay)->format('d-m-Y H:i:s'),
                'delay_minutes' => $delay,
            ],
        ], 202);
    }

    /**
     * Whether a restart is already scheduled, and for when.
     *
     * systemd writes `/run/systemd/shutdown/scheduled` when one is pending —
     * an ini file whose USEC key is the wall-clock microsecond it fires. Read
     * rather than remembered: a reboot can be scheduled from a shell without
     * the panel, and a panel that only knows about its own would confidently
     * report "none" while the machine counts down.
     */
    public function rebootStatus(): JsonResponse
    {
        $path = (string) config('server.reboot.scheduled_file', '/run/systemd/shutdown/scheduled');

        // Read directly, not through ServerOps.
        //
        // systemd writes this into `/run/systemd/shutdown`, which is 0755
        // root: world readable, no elevation required. Routing it through sudo
        // — as the first version of this did, out of habit — made a page-load
        // endpoint depend on a privilege it never needed, and returned 500 on
        // any server whose grant was out of date. The cheapest way to not
        // mishandle a permission is not to need one.
        if (! is_file($path)) {
            return response()->json(['reboot' => ['scheduled' => false, 'at' => null]]);
        }

        preg_match('/^USEC=(\d+)/m', (string) @file_get_contents($path), $matches);

        return response()->json([
            'reboot' => [
                'scheduled' => true,
                // Microseconds since the epoch, per systemd's own format.
                'at' => isset($matches[1])
                    ? Carbon::createFromTimestamp((int) ($matches[1] / 1_000_000))->format('d-m-Y H:i:s')
                    : null,
            ],
        ]);
    }

    /**
     * Call off a scheduled restart.
     *
     * `shutdown -c` is the counterpart of the `-r +N` above, and there was no
     * way to reach it: a restart scheduled an hour out could be watched but
     * not stopped. Cancelling one that is not scheduled is not an error --
     * the user wanted no pending restart and there is none.
     */
    public function cancelReboot(ServerOps $ops, ActivityLogger $log): JsonResponse
    {
        $result = $ops->run(['shutdown', '-c'], ['feature' => 'setting', 'group' => 'reboot', 'op' => 'reboot_cancel']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }

        $log->log('setting.reboot_cancelled', null, []);

        return response()->json(['reboot' => ['scheduled' => false, 'at' => null]]);
    }

    private function save(string $key, FormRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        $group = $settings->find($key);

        if (! $group instanceof SettingGroup || ! $group->available()) {
            abort(404, __('errors/setting.group_unavailable'));
        }

        $group->apply($request->validated());

        $log->log('setting.updated', null, ['group' => $key]);

        return response()->json([$key => $group->read()]);
    }
}
