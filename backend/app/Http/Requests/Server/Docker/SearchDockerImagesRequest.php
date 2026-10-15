<?php

namespace App\Http\Requests\Server\Docker;

use Illuminate\Foundation\Http\FormRequest;

class SearchDockerImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
