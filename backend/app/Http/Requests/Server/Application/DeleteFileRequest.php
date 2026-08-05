<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class DeleteFileRequest extends FormRequest
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
            // A deliberate second field, not just hitting the URL — a floor
            // against firing this by accident. Deleting one file/folder does
            // not need the heavier "type the name" guard Restore uses for a
            // whole-site operation, but it needs more than a bare request.
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }
}
