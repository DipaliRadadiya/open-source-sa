<?php

namespace App\Http\Requests\Server\Cronjob;

use App\Rules\ValidCronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCronjobRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('cronjobs', 'name')->ignore($this->route('cronjob'))],
            'command' => ['sometimes', 'required', 'string', 'max:1000'],
            'expression' => ['sometimes', 'required', 'string', new ValidCronExpression],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
