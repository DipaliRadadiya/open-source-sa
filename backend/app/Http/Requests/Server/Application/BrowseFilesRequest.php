<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by list, view and download — all three only ever read, and all
 * three take the same one input: a path relative to the site's document
 * root.
 */
class BrowseFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['sometimes', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    /**
     * Not named `path()` — that method already exists on the base Request
     * and returns the request's URI path, not this input.
     */
    public function targetPath(): string
    {
        return (string) $this->validated('path', '');
    }
}
