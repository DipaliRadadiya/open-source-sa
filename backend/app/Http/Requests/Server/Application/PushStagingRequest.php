<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushStagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_staging') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'files' is the default the create form should pre-select — it
            // is the only mode that cannot lose data, so it is what a click
            // without a second thought should do.
            'mode' => ['required', Rule::in(['files', 'database', 'full'])],
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
