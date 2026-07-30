<?php

namespace App\Http\Controllers\API\Server;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Http\Requests\Server\Setting\RebootServerRequest;
use App\Http\Requests\Server\Setting\RedisSettingsRequest;
use App\Http\Requests\Server\Setting\SecuritySettingsRequest;
use App\Http\Requests\Server\Setting\SwapSettingsRequest;
use App\Http\Requests\Server\Setting\UpdateSettingsRequest;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;
use App\Services\Server\Settings\SettingsManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * All available setting groups with their current values.
     */
    public function index(SettingsManager $settings): JsonResponse
    {
        return response()->json(['settings' => $settings->all()]);
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

    public function updateRedis(RedisSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('redis', $request, $settings, $log);
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

        return response()->json(['reboot' => ['scheduled' => true, 'when' => $when]], 202);
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
