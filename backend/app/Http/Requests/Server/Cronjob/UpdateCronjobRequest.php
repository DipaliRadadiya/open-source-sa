<?php

namespace App\Http\Requests\Server\Cronjob;

use App\Rules\NotReservedCronFile;
use App\Rules\SingleLine;
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
            'name' => ['sometimes', 'required', 'string', 'max:255', new SingleLine, new NotReservedCronFile, Rule::unique('cronjobs', 'name')->ignore($this->route('cronjob'))],
            'command' => ['sometimes', 'required', 'string', 'max:1000', new SingleLine, 'not_regex:/\{path\}/'],
            'expression' => ['sometimes', 'required', 'string', new ValidCronExpression],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'command.not_regex' => __('errors/cronjob.unresolved_placeholder'),
        ];
    }
}
