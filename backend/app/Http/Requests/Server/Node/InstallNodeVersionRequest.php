<?php

namespace App\Http\Requests\Server\Node;

use Illuminate\Foundation\Http\FormRequest;

class InstallNodeVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('node');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A full semantic version, and nothing else. It reaches a command
            // argument, so the shape is the guard.
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
        ];
    }
}
