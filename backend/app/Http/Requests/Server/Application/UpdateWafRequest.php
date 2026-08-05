<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\WafCategory;
use App\Enums\WafMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateWafRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_firewall') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', new Enum(WafMode::class)],
            'categories' => ['array', 'max:'.count(WafCategory::cases())],
            'categories.*' => [Rule::in(WafCategory::values())],
            // Bounded so this stays "a few plain strings", not a rule editor —
            // 50 entries is already more than anyone has needed in the
            // documented real-world exception cases.
            'exceptions' => ['array', 'max:50'],
            'exceptions.*' => ['string', 'min:1', 'max:255'],
            'custom_rules' => ['array', 'max:50'],
            'custom_rules.*' => ['string', 'min:1', 'max:255'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function mode(): WafMode
    {
        return WafMode::from($this->validated('mode'));
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        return $this->validated('categories') ?? WafCategory::values();
    }

    /**
     * @return array<int, string>
     */
    public function exceptions(): array
    {
        return $this->validated('exceptions') ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function customRules(): array
    {
        return $this->validated('custom_rules') ?? [];
    }
}
