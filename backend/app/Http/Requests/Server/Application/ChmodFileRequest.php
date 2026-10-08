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
            // OLD-26: a leading sticky (1) or setgid (2) digit is accepted —
            // 1777 and 2775 are ordinary folder modes. Setuid (4–7) is not:
            // it lets anyone who can run the file run it as the site's user.
            'mode' => ['required', 'string', 'regex:/^[0-3]?[0-7]{3}$/'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.regex' => __('errors/application.chmod_mode_invalid'),
        ];
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
