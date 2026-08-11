<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class FinalizeUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * The upload id is **not** validated here: it identifies the upload in the
     * URL, exactly as it does for chunk, status and abort. Requiring it in the
     * body as well made finalize the one endpoint of the five that wanted it
     * twice, and rejected the client for sending it once.
     *
     * ChunkedUpload validates it against ID_PATTERN on the way in, so an id
     * that could become a path is still a 404 rather than a filename.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }
}
