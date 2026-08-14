<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\ApiErrorLogReader;
use Illuminate\Foundation\Http\FormRequest;

class ListApiErrorLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['lines' => ['sometimes', 'integer', 'min:1', 'max:'.ApiErrorLogReader::MAX_LINES]];
    }
}
