<?php

namespace App\Http\Requests\Server\Application;

use App\Http\Requests\Server\Application\Concerns\AcceptsManyPaths;
use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class CopyFileRequest extends FormRequest
{
    use AcceptsManyPaths;

    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->pathRules(), [
            // A full target path when one file is named, a destination
            // directory when a selection is: twelve sources cannot share one
            // new name.
            'target' => ['required_without:target_directory', 'string', 'max:1024', new SafeRelativePath],
            'target_directory' => ['required_with:paths', 'string', 'max:1024', new SafeRelativePath],
        ]);
    }

    public function targetDirectory(): string
    {
        return (string) $this->validated('target_directory');
    }

    public function sourcePath(): string
    {
        return (string) $this->validated('path');
    }

    public function targetPath(): string
    {
        return (string) $this->validated('target');
    }
}
