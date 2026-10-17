<?php

namespace App\Http\Requests\Server\SystemUser;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteSystemUsersRequest extends FormRequest
{
    /** Each delete runs userdel -r; a batch is bounded so one request stays short. */
    public const MAX = 50;

    public function authorize(): bool
    {
        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // As for backups: an empty selection is the client's bug, and a
            // stale id fails the request rather than being skipped silently.
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'distinct', 'exists:system_users,id'],
            // FS-C31, as on the single delete: end their sessions first.
            'end_sessions' => ['sometimes', 'boolean'],
        ];
    }
}
