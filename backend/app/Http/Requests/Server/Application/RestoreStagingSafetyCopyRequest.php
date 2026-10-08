<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ST-B3: put a pre-push copy of the live database back. Overwrites the live
 * database, so the same `app_staging` manage a push needs.
 */
class RestoreStagingSafetyCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_staging') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
