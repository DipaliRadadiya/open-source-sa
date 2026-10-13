<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/** A v7 site's `extra_conf`, to be turned into the body of a PHP settings save. */
class TranslateV7ExtraConfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_php') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'extra_conf' => ['present', 'nullable', 'string', 'max:20000'],
        ];
    }
}
