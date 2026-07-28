<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdoptDatabasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'engine' => ['required', Rule::in(array_keys((array) config('server.databases.engines', [])))],
            'names' => ['required', 'array', 'min:1'],
            'names.*' => ['string', 'regex:/^[A-Za-z0-9_]{1,63}$/'],
        ];
    }
}
