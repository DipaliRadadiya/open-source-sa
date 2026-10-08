<?php

namespace App\Http\Requests\Server\Backup;

/**
 * Filters for one application's own backups (BK-K).
 *
 * Under the application's backup permission: someone allowed to back a site
 * up and restore it could not see a single one of its backups without the
 * server-wide Backups permission, which shows every other site's too. The
 * application comes from the URL, so there is no application filter.
 */
class IndexApplicationBackupsRequest extends IndexBackupsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('app_backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), ['filter.application_id' => true]);
    }
}
