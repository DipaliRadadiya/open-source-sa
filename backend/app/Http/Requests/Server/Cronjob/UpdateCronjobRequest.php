<?php

namespace App\Http\Requests\Server\Cronjob;

use App\Rules\ValidCronExpression;
use Illuminate\Foundation\Http\FormRequest;

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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'command' => ['sometimes', 'required', 'string', 'max:1000'],
            'expression' => ['sometimes', 'required', 'string', new ValidCronExpression],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
