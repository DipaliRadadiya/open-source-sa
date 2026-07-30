<?php

namespace App\Http\Requests\Server\Php;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One field: on or off.
 *
 * The extension name itself is a path segment, checked against the live
 * catalog in the controller — a pattern alone is not enough when the value
 * becomes an apt package name.
 */
class PhpExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('php');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
