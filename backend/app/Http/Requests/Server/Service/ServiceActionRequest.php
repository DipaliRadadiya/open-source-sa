<?php

namespace App\Http\Requests\Server\Service;

use App\Services\Server\ServiceManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('service') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(ServiceManager::ACTIONS)],
        ];
    }
}
