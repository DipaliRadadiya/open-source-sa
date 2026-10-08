<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Foundation\Http\FormRequest;

class SaveFileRequest extends FormRequest
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
            // `present`, not `required` (OLD-25): emptying a file is an edit,
            // and the empty string reaches here as null.
            'content' => ['present', 'nullable', 'string', 'max:'.FileBrowser::MAX_BYTES],
            // What `GET` answered as `version` (OLD-27). Sent back, a save
            // over a file someone else changed since is refused with 409
            // instead of silently overwriting their edit. Optional, so an
            // older client still saves.
            'version' => ['sometimes', 'nullable', 'string', 'size:40'],
        ];
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }

    public function expectedVersion(): ?string
    {
        $version = $this->validated('version');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
