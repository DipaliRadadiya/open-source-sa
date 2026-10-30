<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `DELETE /applications/{application}/container/access` (RC-28).
 *
 * Turning container login off leaves the owner's ordinary SSH login as it was,
 * on purpose — their next login is a normal one on the server. QA found the card
 * then said "Off" while the same key still got a shell, so the user is now asked
 * (Dipali, 2026-10-30): `disable_ssh` also turns the owner's SSH login off, in the
 * same request. The server knows the owner; the card does not need its id.
 *
 * Optional and off by default, so the endpoint does exactly what it did for every
 * caller that does not send it.
 */
class DisableContainerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disable_ssh' => ['sometimes', 'boolean'],
        ];
    }
}
