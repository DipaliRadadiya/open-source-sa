<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
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
            // The full target path, not a directory — see FileBrowser::upload()
            // for why the uploaded file's own name is never used to build it.
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
            'file' => ['required', 'file', 'max:'.(FileBrowser::UPLOAD_MAX_BYTES / 1024)],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }
}
