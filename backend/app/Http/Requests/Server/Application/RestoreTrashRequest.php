<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;

class RestoreTrashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The timestamped folder one delete produced. Shape-checked here
            // and again in the service: it is half a filesystem path, and the
            // only defence against `..` in it is that it can never be anything
            // but digits and a dash.
            'batch' => ['required', 'string', 'regex:/^\d{8}-\d{6}$/'],

            // Where it came from, which is also where it goes back to.
            'path' => ['required', 'string', 'max:1024', new SafeRelativePath],
        ];
    }

    public function batch(): string
    {
        return (string) $this->validated('batch');
    }

    public function path(): string
    {
        return (string) $this->validated('path');
    }
}
