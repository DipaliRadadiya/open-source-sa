<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same body as phpMyAdmin's sign-in. The route carries the real bar
 * (`database` manage — the session it opens can write); this only keeps
 * someone with no database access at all from reaching the validator.
 */
class AdminerSsoRequest extends FormRequest
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
            // Which Adminer to open, for a server with more than one. That it is
            // an Active Adminer is checked by the action, where the default
            // choice is made by the same query.
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ];
    }
}
