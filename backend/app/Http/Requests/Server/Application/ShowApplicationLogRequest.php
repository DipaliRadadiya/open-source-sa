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
            // v7's advanced logs: start at a line (1 = the first line of the
            // file), and field filters for an access log.
            'from' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ip' => ['sometimes', 'nullable', 'ip'],
            'status' => ['sometimes', 'nullable', 'string', 'regex:/^[1-5]([0-9]{2}|xx)$/i'],
            'method' => ['sometimes', 'nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS,get,post,put,patch,delete,head,options'],
            'url' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function fields(): array
    {
        return [
            'ip' => $this->validated('ip'),
            'status' => $this->validated('status'),
            'method' => $this->validated('method'),
            'url' => $this->validated('url'),
        ];
    }

    public function hasFields(): bool
    {
        return array_filter($this->fields(), fn ($value) => $value !== null && $value !== '') !== [];
    }
}
