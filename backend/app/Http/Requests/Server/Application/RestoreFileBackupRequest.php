<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class RestoreFileBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
            // Not path-validated the same way — its shape is checked in
            // FileBrowser::restoreBackup() against exactly what backup()
            // writes, and anything else is refused rather than sanitised.
            'backup' => ['required', 'string', 'max:255'],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }

    public function backupName(): string
    {
        return (string) $this->validated('backup');
    }
}
