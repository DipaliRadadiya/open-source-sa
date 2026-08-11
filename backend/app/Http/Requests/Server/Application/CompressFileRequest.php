<?php

namespace App\Http\Requests\Server\Application;

use App\Http\Requests\Server\Application\Concerns\AcceptsManyPaths;
use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class CompressFileRequest extends FormRequest
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
            'target' => ['required', 'string', 'max:1024', new SafeRelativePath],
        ]);
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
