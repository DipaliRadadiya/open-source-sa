<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Enums\LoginShell;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeShellRequest extends FormRequest
{
    /**
     * Allowlisted login shells — user input never picks an arbitrary shell.
     *
     * Derived from the enum rather than repeated, so the list the API
     * publishes and the list it accepts cannot drift apart.
     *
     * @return array<int, string>
     */
    public static function shells(): array
    {
        return LoginShell::paths();
    }

    public function authorize(): bool
    {
        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shell' => ['required', 'string', Rule::in(self::shells())],
        ];
    }
}
