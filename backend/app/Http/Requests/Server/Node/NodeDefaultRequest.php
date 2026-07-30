<?php

namespace App\Http\Requests\Server\Node;

use Illuminate\Foundation\Http\FormRequest;

class NodeDefaultRequest extends FormRequest
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
            'default' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
        ];
    }
}
