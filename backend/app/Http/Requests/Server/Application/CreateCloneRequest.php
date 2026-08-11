<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCloneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_clone') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `applications.name` is unique, so without this a name already in
            // use reaches the database and surfaces as a 500 rather than as
            // the field error it is.
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('applications', 'name')],
            'domain' => [
                'required', 'string', 'max:255',
                'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',
                Rule::unique('applications', 'domain'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower(trim((string) $this->input('domain')))]);
        }
    }

    public function domain(): string
    {
        return (string) $this->validated('domain');
    }
}
