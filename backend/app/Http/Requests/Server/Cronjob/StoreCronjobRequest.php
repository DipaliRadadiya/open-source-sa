<?php

namespace App\Http\Requests\Server\Cronjob;

use App\Rules\ValidCronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCronjobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('cronjob') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('cronjobs', 'name')],
            // Either target a panel System User, or a raw OS username (default/
            // unmanaged account). At least one must be present.
            'system_user_id' => ['nullable', 'exists:system_users,id'],
            'username' => ['required_without:system_user_id', 'nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'command' => ['required', 'string', 'max:1000'],
            'expression' => ['required', 'string', new ValidCronExpression],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
