<?php

namespace App\Http\Requests\Server\Docker;

use App\Models\Registry;
use App\Services\Server\Docker\Images\ImageReference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The image discovery reads: tags and inspect.
 *
 * `application` (view), because the form these feed is the create-application
 * form. A stored registry credential is a separate power: using one to read a
 * private image needs `registry` (view), the permission the credential picker
 * on that same form already requires to list them.
 */
class DockerImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->canView('application')) {
            return false;
        }

        return ! $this->filled('registry_id') || $user->canView('registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'string', 'max:255'],
            'registry_id' => ['nullable', 'integer', 'exists:registries,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('image') && $this->reference() === null) {
                    $validator->errors()->add('image', __('docker.image.invalid_reference'));
                }
            },
        ];
    }

    public function reference(): ?ImageReference
    {
        return ImageReference::parse((string) $this->input('image'));
    }

    public function credential(): ?Registry
    {
        return $this->filled('registry_id') ? Registry::find((int) $this->input('registry_id')) : null;
    }
}
