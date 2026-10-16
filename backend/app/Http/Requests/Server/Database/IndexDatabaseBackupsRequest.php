<?php

namespace App\Http\Requests\Server\Database;

use App\Http\Requests\Server\Backup\IndexBackupsRequest;

/**
 * One database's own backups, under the database's permission — the same
 * reasoning as a site's list (BK-K). The database comes from the URL.
 */
class IndexDatabaseBackupsRequest extends IndexBackupsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['filter.application_id', 'filter.database_id', 'filter.archived', 'filter.engine']));
    }
}
