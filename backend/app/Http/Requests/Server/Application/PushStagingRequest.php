<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Models\BackupTarget;
use App\Services\Server\Backups\StaleBackupReaper;
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
            // 'files' is the default the create form should pre-select: it
            // leaves the live database alone. It is not lossless — it deletes
            // files that exist only on the live site (ST-B4) — and 'database'
            // and 'full' replace the live database, which is saved first.
            'mode' => ['required', Rule::in(['files', 'database', 'full'])],
            // FS-B10: take a full backup of the live site through its own
            // backup setup first, and push only if it verified.
            'backup' => ['sometimes', 'boolean'],
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

            if (! $application instanceof Application || ! $this->wantsBackup()) {
                return;
            }

            // Taking a backup is the backup feature's power, not staging's.
            if (! ($this->user()?->canManage('app_backup') ?? false)) {
                $validator->errors()->add('backup', __('errors/application.staging_backup_not_permitted'));

                return;
            }

            $target = BackupTarget::where('application_id', $application->id)->first();

            if ($target === null) {
                $validator->errors()->add('backup', __('backup.errors.not_configured'));
            } elseif (app(StaleBackupReaper::class)->hasLiveRun($target)) {
                $validator->errors()->add('backup', __('backup.errors.already_running'));
            }
        }];
    }

    public function wantsBackup(): bool
    {
        return $this->boolean('backup');
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
