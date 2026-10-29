<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Update staging from live. Overwrites the staging site, so the same
 * `app_staging` manage a push needs. Live is only read.
 */
class PullStagingRequest extends FormRequest
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
            // As for a push: `files` leaves staging's database alone, and
            // `database`/`full` replace it — staging's is dumped first.
            'mode' => ['required', Rule::in(['files', 'database', 'full'])],
            // Dump the database being replaced first, so it can be put back
            // (the Staging screen's safety copies). The user's choice since
            // 2026-10-29; on unless they turn it off.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    /**
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

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
