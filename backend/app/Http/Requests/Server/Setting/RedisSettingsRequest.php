<?php

namespace App\Http\Requests\Server\Setting;

use App\Services\Server\Settings\RedisSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RedisSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "0" (unlimited) or a size like "256mb" / "1gb".
            'maxmemory' => ['required', 'string', 'regex:/^(0|\d+(kb|mb|gb|b)?)$/i'],
            'maxmemory_policy' => [
                'required',
                Rule::in((array) config('server.redis_maxmemory_policies', [])),
                // See RedisSettings::unsafePolicies(): these can silently drop
                // the panel's own queued jobs.
                Rule::notIn(RedisSettings::unsafePolicies()),
            ],
            // Absent means "leave it alone" — the read side never returns
            // the password, so an unchanged form has nothing to send back.
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            // Clearing needs its own flag: Laravel's ConvertEmptyStringsToNull
            // turns "" into null before validation, so an empty password is
            // indistinguishable from an omitted one by the time it gets here.
            'remove_password' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maxmemory_policy.not_in' => __('setting.redis.policy_evicts_panel_queue'),
        ];
    }
}
