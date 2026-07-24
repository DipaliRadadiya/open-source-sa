<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
