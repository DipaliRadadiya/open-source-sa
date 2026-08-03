<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['sometimes', 'string', 'max:255'],

            // Which application's sidebar is being built. Optional, and the
            // difference matters: with it the answer is filtered to what that
            // site type can actually do; without it the answer is every
            // application-level item, which is what the role form needs — an
            // admin assigning a role is not looking at one site.
            'application_id' => ['sometimes', 'integer', 'exists:applications,id'],
        ];
    }
}
