<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class ExtractFileRequest extends FormRequest
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
            // `present`, not `required` (2026-10-29): the application's own
            // folder is the empty path — what the dialog's "Application folder"
            // sends — and `required` refused it, so an archive could not be
            // unpacked there at all. A request that leaves `target` out is
            // still refused.
            'target' => ['present', 'nullable', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    public function archivePath(): string
    {
        return (string) $this->validated('path');
    }

    public function targetPath(): string
    {
        return (string) $this->validated('target');
    }
}
