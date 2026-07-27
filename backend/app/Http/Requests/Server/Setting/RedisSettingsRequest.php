<?php

namespace App\Http\Requests\Server\Setting;

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
            'maxmemory_policy' => ['required', Rule::in((array) config('server.redis_maxmemory_policies', []))],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
