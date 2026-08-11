<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use App\Services\Server\Applications\ChunkedUpload;
use Illuminate\Foundation\Http\FormRequest;

class FinalizeUploadRequest extends FormRequest
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
            'upload_id' => ['required', 'string', 'regex:'.ChunkedUpload::ID_PATTERN],
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    public function uploadId(): string
    {
        return (string) $this->validated('upload_id');
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }
}
