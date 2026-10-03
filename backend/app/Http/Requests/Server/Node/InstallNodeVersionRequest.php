<?php

namespace App\Http\Requests\Server\Node;

use App\Services\Server\Runtimes\NodeRuntime;
use Illuminate\Foundation\Http\FormRequest;

class InstallNodeVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('node');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A full semantic version, and nothing else. It reaches a command
            // argument, so the shape is the guard.
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/',
                // A real release, not just the shape of one (bug #31): 99.0.0
                // was accepted and left a failed entry behind.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (app(NodeRuntime::class)->releaseExists((string) $value) === false) {
                        $fail(__('errors/node.version_unknown', ['version' => $value]));
                    }
                },
            ],
        ];
    }
}
