<?php

namespace App\Http\Requests\Server\Setting;

use App\Services\Server\Settings\SwapSettings;
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
        // Computed per machine, not a constant. On a box with enough RAM — or
        // with its own swap partition — this is 0 and swap can be switched off
        // freely; on a 1 GB VPS it is what keeps the panel able to update
        // itself. See `SwapSettings::minimumMb()` for the arithmetic, which is
        // install.sh's own.
        $minimum = app(SwapSettings::class)->minimumMb();

        return [
            // 0 disables (swapoff + remove file + strip fstab line); >0
            // creates/resizes. 0 is still reachable whenever the floor is 0,
            // which is the common case on a well-specified server.
            'size_mb' => [
                'required',
                'integer',
                'min:'.$minimum,
                'max:'.(int) config('server.swap_max_mb', 65536),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $swap = app(SwapSettings::class);

        return [
            // Not the framework's "must be at least N". This refusal needs to
            // say what breaks, because the consequence is invisible from the
            // Memory screen: the panel builds its own frontend to update
            // itself, and below this figure that build is killed. Naming the
            // escape hatch too — an operator running their own swap is not
            // wrong, they just have to say so.
            'size_mb.min' => __('errors/setting.swap_below_minimum', [
                'minimum' => $swap->minimumMb(),
                'required' => $swap->buildRequirementMb(),
            ]),
        ];
    }
}
