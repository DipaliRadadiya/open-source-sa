<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The server activity log's query string.
 *
 * It had none: `per_page=-5` answered a 500 and `per_page=100000` returned the
 * whole table in one response. Same vocabulary as the other two logs.
 */
class ListServerActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('activity_log') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.action' => ['sometimes', 'string', 'max:255'],
            'filter.type' => ['sometimes', 'string', 'max:255'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
        ];
    }
}
