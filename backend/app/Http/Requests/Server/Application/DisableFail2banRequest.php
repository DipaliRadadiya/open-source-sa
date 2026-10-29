<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning a site's fail2ban jail off. Its one field is whether to keep the
 * files being removed until the reload has held (2026-10-29, the user's
 * choice); off, they are held in memory instead.
 */
class DisableFail2banRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_fail2ban') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }
}
