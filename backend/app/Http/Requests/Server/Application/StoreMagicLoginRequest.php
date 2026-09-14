<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class StoreMagicLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManage('app_magic_login');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Shape only. Whether this id belongs to an administrator of *this*
            // site is not a question the validator can answer — it lives in the
            // site's own database, not ours — so the action re-reads the list
            // and refuses an id that is not on it.
            'wp_user_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
