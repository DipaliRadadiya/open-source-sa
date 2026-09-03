<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class MysqlSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The floor is a lockout guard, not a tuning opinion: the panel's
            // own pool needs connections too, and a server set to 1 is a server
            // this screen can no longer reach to undo it.
            //
            // The ceiling is deliberately generous. What is *sensible* for the
            // box is computed and shown next to the field, but it is advice —
            // an operator with a workload we cannot see is allowed to exceed
            // it. Only the absurd is refused, because past this the engine
            // caps the value itself and the number stops meaning anything.
            'max_connections' => ['required', 'integer', 'min:10', 'max:100000'],
        ];
    }
}
