<?php

namespace App\Http\Requests\Server\Sync;

use App\Services\Server\Sync\ServerSync;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IgnoreSyncItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('sync') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Constrained to the registered types, so a typo cannot create an
            // ignore that silently matches nothing forever.
            'resource_type' => ['required', Rule::in(app(ServerSync::class)->resourceTypes())],
            'resource_key' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
