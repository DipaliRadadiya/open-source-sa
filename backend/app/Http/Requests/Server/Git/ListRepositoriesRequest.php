<?php

namespace App\Http\Requests\Server\Git;

use Illuminate\Foundation\Http\FormRequest;

class ListRepositoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('git') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
