<?php

namespace App\Http\Requests\Server\Process;

use App\Services\Server\ProcessKiller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KillProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // TERM by default: it lets the process flush and close files.
            // Only these two are offered — the rest are either meaningless
            // here or a way to do something other than stop a process.
            'signal' => ['sometimes', Rule::in(ProcessKiller::SIGNALS)],
        ];
    }
}
