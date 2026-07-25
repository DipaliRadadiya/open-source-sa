<?php

namespace App\Http\Requests\Server\SystemUser;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeShellRequest extends FormRequest
{
    /**
     * Allowlisted login shells — user input never picks an arbitrary shell.
     */
    public const SHELLS = ['/bin/bash', '/bin/sh', '/usr/bin/zsh', '/usr/sbin/nologin', '/bin/false'];

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
            'shell' => ['required', 'string', Rule::in(self::SHELLS)],
        ];
    }
}
