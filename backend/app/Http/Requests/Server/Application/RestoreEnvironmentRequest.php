<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Server\Applications\ApplicationEnvironment;
use Illuminate\Foundation\Http\FormRequest;

class RestoreEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // As SaveEnvironmentRequest: the route middleware enforces this too.
        return $this->user()?->canManage('app_environment') ?? false;
    }

    public function rules(): array
    {
        return [
            // A name from the backups list, verbatim. It becomes half a path,
            // so anything else is refused rather than sanitised — as a 422.
            // It used to reach the service unvalidated and come back as a 500
            // with an error logged (found live 2026-09-29).
            'backup' => ['required', 'string', 'max:64', 'regex:'.ApplicationEnvironment::BACKUP_NAME],
            'restart' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'backup.regex' => __('errors/application.unknown_backup'),
        ];
    }
}
