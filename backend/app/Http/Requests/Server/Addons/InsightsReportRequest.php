<?php

namespace App\Http\Requests\Server\Addons;

use Illuminate\Foundation\Http\FormRequest;

class InsightsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('central_authenticated') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
