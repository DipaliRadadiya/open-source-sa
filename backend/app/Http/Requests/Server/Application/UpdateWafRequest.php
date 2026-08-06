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
     * Null means "leave the stored categories alone", which is not the same
     * as `[]` — that means "switch every category off".
     *
     * Absent used to mean "turn all six on", and that is a genuine footgun: a
     * caller sending a partial update to change only the mode would silently
     * re-enable every category the user had switched off, including the one
     * they turned off to fix a false positive. Nothing in the payload said so
     * and nothing failed. Same contract as the bot blocker's block/allow
     * lists, so the two screens behave identically.
     *
     * @return array<int, string>|null
     */
    public function categories(): ?array
    {
        return $this->has('categories') ? (array) $this->validated('categories') : null;
    }

    /**
     * Same contract as categories(): absent leaves the stored list alone,
     * `[]` clears it.
     *
     * @return array<int, string>|null
     */
    public function exceptions(): ?array
    {
        return $this->has('exceptions') ? (array) $this->validated('exceptions') : null;
    }

    /**
     * Same contract as categories(): absent leaves the stored list alone,
     * `[]` clears it.
     *
     * @return array<int, string>|null
     */
    public function customRules(): ?array
    {
        return $this->has('custom_rules') ? (array) $this->validated('custom_rules') : null;
    }
}
