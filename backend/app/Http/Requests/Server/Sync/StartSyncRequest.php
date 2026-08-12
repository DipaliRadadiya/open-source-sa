<?php

namespace App\Http\Requests\Server\Sync;

use App\Enums\SyncMode;
use App\Services\Server\Sync\ServerSync;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSyncRequest extends FormRequest
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
            // Defaults to preview in the controller, not here: a caller who
            // omits the field must never accidentally get the writing one.
            'mode' => ['sometimes', Rule::enum(SyncMode::class)],

            'only' => ['sometimes', 'array'],
            'only.*' => [Rule::in(app(ServerSync::class)->resourceTypes())],

            // Opt-in, and only meaningful once firewall discovery exists.
            // Adopting a rule means a later sync could treat it as the
            // panel's to remove, which is the one irreversible mistake here.
            'include_firewall' => ['sometimes', 'boolean'],

            // Shows what has been dismissed. Off by default — that is what
            // makes ignoring worth doing — but reachable, or an ignore made
            // by mistake is unreachable from the screen that made it.
            'include_ignored' => ['sometimes', 'boolean'],
        ];
    }
}
