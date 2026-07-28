<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMyActivityLogRequest extends FormRequest
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
            // Same filter vocabulary as the admin log, minus `filter.user_id`:
            // this endpoint is always scoped to the caller, so there is no
            // user to choose.
            'filter' => ['sometimes', 'array'],
            'filter.action' => ['sometimes', 'string', 'max:255'],
            'filter.type' => ['sometimes', 'string', 'max:255'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
        ];
    }
}
