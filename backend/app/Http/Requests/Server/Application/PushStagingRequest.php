<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Bug #88: a push with no staging copy reached StagingManager, which can
     * only answer a generic 500.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $application = $this->route('application');

            if ($application instanceof Application && ! $application->staging()->exists()) {
                $validator->errors()->add('application', __('errors/application.staging_missing'));
            }
        }];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
