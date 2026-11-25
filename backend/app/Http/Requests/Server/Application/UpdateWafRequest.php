<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\WafCategory;
use App\Enums\WafMode;
use App\Models\ApplicationWafRule;
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
            // Bounded so this stays "a few plain strings", not a rule editor.
            'exceptions' => ['array', 'max:50'],
            // No control characters: a newline breaks the config test. The minimum length matters
            // because an exception skips every check for paths containing it (`/` = firewall off).
            // No `..` or `?` either: exceptions match the path as sent, so they never occur in one.
            'exceptions.*' => ['string', 'min:'.ApplicationWafRule::EXCEPTION_MIN_LENGTH, 'max:255', 'not_regex:/[\x00-\x1F\x7F]/', 'not_regex:/\.\.|\?/'],
            'custom_rules' => ['array', 'max:50'],
            'custom_rules.*' => ['string', 'min:1', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /**
     * Sentences rather than "The exceptions.0 format is invalid".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exceptions.*.not_regex' => __('validation.waf_exception_characters'),
            'custom_rules.*.not_regex' => __('validation.waf_rule_characters'),
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
     * Null means "leave the stored categories alone"; `[]` means "switch every category off".
     * A partial update (e.g. only the mode) must not re-enable categories the user turned off.
     * Same contract as the bot blocker's lists.
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
