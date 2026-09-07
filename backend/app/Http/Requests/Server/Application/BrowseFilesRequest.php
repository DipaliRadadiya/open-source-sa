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
            // Only the listing reads this; view and download share this request
            // and ignore it, which is cheaper than a fourth request class for
            // one optional flag.
            'hidden' => ['sometimes', 'in:0,1'],
        ];
    }

    /**
     * Whether dotfiles belong in the listing.
     *
     * Defaults to true, which is what this screen has always done: the browser
     * has never filtered them. A new option must not change what an existing
     * user sees until they ask it to, and defaulting the other way would make
     * `.env` disappear for everyone who has used the file manager before.
     */
    public function includeHidden(): bool
    {
        return (string) $this->validated('hidden', '1') !== '0';
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
