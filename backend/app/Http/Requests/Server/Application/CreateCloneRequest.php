<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\NotPanelHost;
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
                // A staging or clone gets a domains row as its primary, and that
                // table's unique index covers every alias on the server too — an
                // alias of another site passed the check above and then failed
                // on insert.
                Rule::unique('application_domains', 'domain'),
                new NotPanelHost,
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
