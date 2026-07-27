<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class SwapSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 0 disables (swapoff + remove file + strip fstab line); >0 creates/resizes.
            'size_mb' => ['required', 'integer', 'min:0', 'max:'.(int) config('server.swap_max_mb', 65536)],
        ];
    }
}
