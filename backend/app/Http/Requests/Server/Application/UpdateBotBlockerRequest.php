<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\AiBotPolicy;
use App\Rules\BotUserAgent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBotBlockerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_bot_blocker') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policy' => ['required', new Enum(AiBotPolicy::class)],
            // Both lists are replaced wholesale when present and left alone
            // when absent, so a caller that only changes the policy does not
            // have to resend the rules to keep them. Bounded for the same
            // reason the WAF's lists are: this is a short list of agents, not
            // a rule editor, and every entry costs a branch in the vhost.
            'blocked' => ['sometimes', 'array', 'max:50'],
            'blocked.*' => ['string', new BotUserAgent],
            'allowed' => ['sometimes', 'array', 'max:50'],
            'allowed.*' => ['string', new BotUserAgent],
        ];
    }

    public function policy(): AiBotPolicy
    {
        return AiBotPolicy::from($this->validated('policy'));
    }

    /**
     * Null means "leave the stored list alone" — distinct from an empty
     * array, which means "remove every rule of this kind".
     *
     * @return array<int, string>|null
     */
    public function blocked(): ?array
    {
        return $this->normalize('blocked');
    }

    /**
     * @return array<int, string>|null
     */
    public function allowed(): ?array
    {
        return $this->normalize('allowed');
    }

    /**
     * @return array<int, string>|null
     */
    private function normalize(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return collect((array) $this->validated($key))
            ->map(fn (string $value) => trim($value))
            ->filter()
            // Deduped case-insensitively: the vhost match is case-insensitive,
            // so `GPTBot` and `gptbot` are the same rule and storing both
            // would put the same alternation in the pattern twice.
            ->unique(fn (string $value) => mb_strtolower($value))
            ->values()
            ->all();
    }
}
