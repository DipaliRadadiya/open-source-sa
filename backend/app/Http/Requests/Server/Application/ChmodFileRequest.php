<?php

namespace App\Http\Requests\Server\Application;

use App\Http\Requests\Server\Application\Concerns\AcceptsManyPaths;
use Illuminate\Foundation\Http\FormRequest;

class ChmodFileRequest extends FormRequest
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
            // Exactly 3 octal digits — no setuid/setgid/sticky. See
            // FileBrowser::chmod() for why a fourth digit is refused.
            'mode' => ['required', 'string', 'regex:/^[0-7]{3}$/'],
        ]);
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
