<?php

namespace App\Http\Requests\Server\Operation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RC-20: the running-jobs list. Any signed-in user may ask; what they get back
 * is cut down to the kinds their role can see (OperationFeed).
 */
class IndexOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only queued and running work — what a header indicator polls.
            'active' => ['sometimes', 'boolean'],
            // How far back finished work is included otherwise.
            'hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
        ];
    }
}
