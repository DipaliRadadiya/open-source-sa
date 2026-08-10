<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;

class PhpmyadminSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'database_user_id' => ['nullable', 'integer', 'exists:database_users,id'],
        ];
    }
}
