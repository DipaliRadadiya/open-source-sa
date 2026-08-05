<?php

namespace App\Enums;

/**
 * Three choices, not a switch — a blunt on/off would block AI search
 * crawlers (ChatGPT search, Perplexity, …) exactly as hard as the training
 * scrapers that never send a visitor back, silently costing the site owner
 * traffic they wanted to keep. See `config/ai_bots.php` for which bot goes in
 * which bucket.
 */
enum AiBotPolicy: string
{
    /** No AI crawler is blocked. Default for every new site. */
    case AllowAll = 'allow_all';

    /** Blocks bots that scrape content for model training only. */
    case BlockTraining = 'block_training';

    /** Blocks every known AI bot, training and retrieval alike. */
    case BlockAll = 'block_all';

    public function title(): string
    {
        return __('app_bot_blocker.policies.'.$this->value.'.title');
    }

    public function description(): string
    {
        return __('app_bot_blocker.policies.'.$this->value.'.description');
    }

    /**
     * The bot names this policy actually blocks, resolved from
     * `config/ai_bots.php` — one source of truth, so the vhost template, the
     * API response and the frontend's transparency panel can never drift
     * apart from each other.
     *
     * @return array<int, string>
     */
    public function blockedBots(): array
    {
        return match ($this) {
            self::AllowAll => [],
            self::BlockTraining => (array) config('ai_bots.training'),
            self::BlockAll => array_merge((array) config('ai_bots.training'), (array) config('ai_bots.retrieval')),
        };
    }
}
