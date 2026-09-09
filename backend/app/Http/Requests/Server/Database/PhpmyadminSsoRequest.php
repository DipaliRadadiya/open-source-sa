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
            // Which phpMyAdmin to open. Optional, because a server with one
            // installation should not have to name it — but a server with
            // several had no way to say, and the action simply took the first
            // row it found. A client could not work around that either: the
            // sign-in token is written *into the chosen site's directory*, so
            // there is nothing to redirect afterwards.
            //
            // `exists` only checks the row is an application; that it is an
            // Active phpMyAdmin is checked where the same rule already
            // governs the fallback, so both paths cannot disagree.
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ];
    }
}
