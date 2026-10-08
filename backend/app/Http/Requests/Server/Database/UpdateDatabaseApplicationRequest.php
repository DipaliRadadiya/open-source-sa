<?php

namespace App\Http\Requests\Server\Database;

use App\Http\Requests\Server\Database\Concerns\ChecksApplicationPairing;
use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Attach this database to an application, move it to another, or detach it.
 *
 * One nullable field rather than three verbs: the whole operation is setting
 * `databases.application_id`, and attach/move/detach are the three values it
 * can take.
 */
class UpdateDatabaseApplicationRequest extends FormRequest
{
    use ChecksApplicationPairing;

    public function authorize(): bool
    {
        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Present-but-null is the detach case, so `present` rather than
            // `required`: an absent key would otherwise silently detach.
            'application_id' => ['present', 'nullable', 'integer', Rule::exists('applications', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $applicationId = $this->validated('application_id');

            if ($applicationId === null) {
                return;
            }

            $application = Application::find($applicationId);

            if ($application === null) {
                return;
            }

            $this->refuseSecondDatabase($validator, $application, $this->route('database')?->id);
            $this->refuseUnusableEngine($validator, $application, (string) $this->route('database')->engine);
        });
    }
}
