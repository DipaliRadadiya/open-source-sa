<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class BeginUploadRequest extends FormRequest
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
            // The full target path, not a directory — same contract as the
            // single-shot upload endpoint, so the uploaded file's own name is
            // never used to build a path.
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
            // The file's total size, so the disk can be checked once up front
            // instead of only discovering it is full an hour in. Optional: a
            // client that does not send it still gets the per-chunk guard, it
            // just finds out later.
            'size' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }

    public function expectedBytes(): int
    {
        return (int) ($this->validated('size') ?? 0);
    }
}
