<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Server\Applications\ApplicationLogManager;
use Illuminate\Foundation\Http\FormRequest;

class ShowApplicationLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `app_log`, not `logs`. A site's own error log and the server's
        // auth.log are different things to be trusted with — sharing one
        // permission across that line would be privilege escalation dressed
        // up as a filter.
        return $this->user()?->canView('app_log') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:'.ApplicationLogManager::MAX_LINES],
            'grep' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
