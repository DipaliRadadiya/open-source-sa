<?php

namespace App\Http\Requests\Server\Setting;

use App\Services\Server\Settings\RebootScheduleSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RebootScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('setting');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // A fixed list, never a cron expression. This restarts the
            // machine; `* * * * *` would be a reboot loop nobody can log in
            // to stop.
            'frequency' => ['required_if:enabled,true', Rule::in(RebootScheduleSettings::FREQUENCIES)],
            'hour' => ['required_if:enabled,true', 'integer', 'between:0,23'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'day_of_month' => __('setting.reboot_schedule.day_of_month'),
        ];
    }
}
