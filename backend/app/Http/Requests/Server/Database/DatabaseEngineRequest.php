<?php

namespace App\Http\Requests\Server\Database;

use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The `?engine=` every per-engine monitor/listing endpoint needs. A missing
 * or unknown engine is a malformed request (422 naming the field), not a
 * missing resource — these URLs exist whatever the engine is.
 */
class DatabaseEngineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'engine' => ['required', 'string', Rule::in(app(DatabaseManager::class)->engineNames())],
        ];
    }

    public function engine(): string
    {
        return (string) $this->validated('engine');
    }
}
