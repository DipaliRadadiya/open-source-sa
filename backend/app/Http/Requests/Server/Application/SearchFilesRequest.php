<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class SearchFilesRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'path' => ['sometimes', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path', '');
    }

    /**
     * Not named `query()` — that method already exists on the base Request
     * and returns the whole query string, not this input.
     */
    public function searchQuery(): string
    {
        return (string) $this->validated('q');
    }
}
