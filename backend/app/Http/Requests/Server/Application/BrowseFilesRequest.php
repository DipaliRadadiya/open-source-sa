<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by list, view and download: all only read, and all take one path relative to the
 * site's document root.
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
            // Nullable: `?path=` arrives as null (ConvertEmptyStringsToNull) and means the site root.
            'path' => ['sometimes', 'nullable', 'string', 'max:1024', new SafeRelativePath],
            // Only the listing reads this; view and download share the request and ignore it.
            'hidden' => ['sometimes', 'in:0,1'],
            // Folder sizes only: measure now instead of using the five-minute cache.
            'refresh' => ['sometimes', 'in:0,1'],
        ];
    }

    /**
     * Whether dotfiles belong in the listing.
     *
     * Defaults to true: the file manager has always shown dotfiles, and hiding `.env` by
     * default would change what existing users see.
     */
    public function includeHidden(): bool
    {
        return (string) $this->validated('hidden', '1') !== '0';
    }

    /**
     * Not named `path()`: the base Request already has one, returning the URI path.
     */
    public function refresh(): bool
    {
        return (string) $this->validated('refresh', '0') === '1';
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path', '');
    }
}
