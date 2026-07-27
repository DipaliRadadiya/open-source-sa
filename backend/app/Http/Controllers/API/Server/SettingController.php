<?php

namespace App\Http\Controllers\API\Server;

use App\Contracts\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Http\Requests\Server\Setting\RedisSettingsRequest;
use App\Http\Requests\Server\Setting\SecuritySettingsRequest;
use App\Http\Requests\Server\Setting\UpdateSettingsRequest;
use App\Services\ActivityLogger;
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

    public function updateRedis(RedisSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('redis', $request, $settings, $log);
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
