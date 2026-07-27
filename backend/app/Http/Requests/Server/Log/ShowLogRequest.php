<?php

namespace App\Http\Requests\Server\Log;

use App\Services\Server\LogManager;
use Illuminate\Foundation\Http\FormRequest;

class ShowLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('logs') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:'.LogManager::MAX_LINES],
            'grep' => ['sometimes', 'nullable', 'string', 'max:200'],
            'after' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
