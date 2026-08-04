<?php

namespace App\Http\Requests\Server\Backup;

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Models\Backup;
use App\Models\Restore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries `permission:backup,manage`; this is the
        // second half of the rule, kept here so a route added later without
        // the middleware still cannot restore.
        return $this->user()?->canManage('backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['filesystem', 'database', 'full'])],
            // Typed, not clicked. Everything else in this feature is a guard
            // against the system going wrong; this is the guard against the
            // person going wrong, and it is the more common failure.
            'confirm' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Backup $backup */
            $backup = $this->route('backup');

            if ($backup->status !== BackupStatus::Verified) {
                // An unverified backup is one we could not prove arrived
                // intact. Restoring from it would overwrite a working site
                // with something we already know we cannot vouch for.
                $validator->errors()->add('backup', __('backup.errors.restore_unverified'));

                return;
            }

            if ($backup->application === null) {
                $validator->errors()->add('backup', __('backup.errors.restore_no_application'));

                return;
            }

            if ($this->string('confirm')->trim()->value() !== $backup->application->domain) {
                $validator->errors()->add('confirm', __('backup.errors.restore_confirm'));
            }

            $inFlight = Restore::query()
                ->where('application_id', $backup->application_id)
                ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
                ->exists();

            if ($inFlight) {
                $validator->errors()->add('backup', __('backup.errors.restore_already_running'));
            }

            // What was asked for must be a subset of what the archive holds.
            // `full` from a database-only backup would otherwise swap an empty
            // directory over a working site.
            $requested = $this->requestedType();
            $wantsFiles = in_array($requested, ['filesystem', 'full'], true);
            $wantsDatabase = in_array($requested, ['database', 'full'], true);
            $hasFiles = in_array($backup->type->value, ['filesystem', 'full'], true);
            $hasDatabase = in_array($backup->type->value, ['database', 'full'], true);

            if ($wantsDatabase && ! $hasDatabase) {
                $validator->errors()->add('type', __('backup.errors.restore_no_database'));
            }

            if ($wantsFiles && ! $hasFiles) {
                $validator->errors()->add('type', __('backup.errors.restore_no_files'));
            }
        });
    }

    /**
     * Defaults to whatever the backup holds. Asking for `full` from a
     * database-only backup would restore an empty site directory over a
     * working one.
     */
    public function requestedType(): string
    {
        /** @var Backup $backup */
        $backup = $this->route('backup');

        return $this->string('type')->trim()->value() ?: $backup->type->value;
    }
}
