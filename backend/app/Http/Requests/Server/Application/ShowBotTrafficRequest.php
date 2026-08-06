<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Server\Applications\BotTrafficReporter;
use Illuminate\Foundation\Http\FormRequest;

class ShowBotTrafficRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('app_log') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'between:1,'.BotTrafficReporter::MAX_DAYS],
        ];
    }
}
