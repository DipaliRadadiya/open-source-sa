<?php

namespace App\Http\Requests\Server\Php;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Installing or removing the ionCube loader. Its one field is whether to keep
 * a recovery copy of the files it changes until the change has held;
 * off, they are held in memory instead.
 */
class IonCubeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('php');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }
}
