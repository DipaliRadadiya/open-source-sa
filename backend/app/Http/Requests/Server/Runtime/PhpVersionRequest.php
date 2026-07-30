<?php

namespace App\Http\Requests\Server\Runtime;

use Illuminate\Foundation\Http\FormRequest;

class PhpVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('setting');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // major.minor only — PHP packages are named that way, and the
            // value reaches both a package name and a path.
            'version' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ];
    }
}
