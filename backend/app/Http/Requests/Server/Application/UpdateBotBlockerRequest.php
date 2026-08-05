<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\AiBotPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBotBlockerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_bot_blocker') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policy' => ['required', new Enum(AiBotPolicy::class)],
        ];
    }

    public function policy(): AiBotPolicy
    {
        return AiBotPolicy::from($this->validated('policy'));
    }
}
