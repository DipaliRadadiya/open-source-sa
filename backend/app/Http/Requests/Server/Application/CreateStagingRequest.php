<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Rules\Hostname;
use App\Rules\NotPanelHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateStagingRequest extends FormRequest
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
            'domain' => [
                'required', 'string', 'max:255',
                new Hostname,
                Rule::unique('applications', 'domain'),
                // A staging or clone gets a domains row as its primary, and that
                // table's unique index covers every alias on the server too — an
                // alias of another site passed the check above and then failed
                // on insert.
                Rule::unique('application_domains', 'domain'),
                new NotPanelHost,
            ],
        ];
    }

    /**
     * What the site itself rules out, as a 422 the user can read.
     *
     * Bug #88: both cases reached StagingManager, which can only throw a
     * generic 500 with a reference, for something that is not a server
     * failure at all. Bug #89: nothing refused a staging copy of a staging
     * copy, which outlives the first one as a normal-looking site that still
     * swallows every email and hides from search engines.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $application = $this->route('application');

            if (! $application instanceof Application) {
                return;
            }

            if ($application->production_application_id !== null) {
                $validator->errors()->add('application', __('errors/application.staging_of_staging'));
            } elseif ($application->staging()->exists()) {
                $validator->errors()->add('application', __('errors/application.staging_exists'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower(trim((string) $this->input('domain')))]);
        }
    }

    public function domain(): string
    {
        return (string) $this->validated('domain');
    }
}
