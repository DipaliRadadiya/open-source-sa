<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Start a one-click update. There is nothing to choose: the target is the
 * newest release in the installed major, worked out on the server. Whether
 * the site can be updated at all is decided by StartApplicationUpdate.
 */
class StartApplicationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
