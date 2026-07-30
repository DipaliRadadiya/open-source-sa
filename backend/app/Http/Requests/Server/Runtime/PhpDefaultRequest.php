<?php

namespace App\Http\Requests\Server\Runtime;

use Illuminate\Foundation\Http\FormRequest;

class PhpDefaultRequest extends FormRequest
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
            'default' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ];
    }
}
