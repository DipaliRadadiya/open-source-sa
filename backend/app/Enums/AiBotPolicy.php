<?php

namespace App\Enums;

/**
 * Four choices, not a switch — a blunt on/off would block AI search crawlers
 * (ChatGPT search, Perplexity, …) exactly as hard as the training scrapers
 * that never send a visitor back, silently costing the site owner traffic
 * they wanted to keep.
 *
 * The middle two are the interesting ones, and they exist because "AI bot" is
 * three things: a training scraper, a search crawler that earns citations,
 * and a live assistant fetching one page for one person. See
 * `config/ai_bots.php` for which bot goes in which bucket.
 */
enum AiBotPolicy: string
{
    /** No AI crawler is blocked. Default for every new site. */
    case AllowAll = 'allow_all';

    /** Blocks bots that scrape content for model training only. */
    case BlockTraining = 'block_training';

    /**
     * Training scrapers and live AI assistants, but not the search crawlers
     * — the site stops serving one-off assistant fetches while staying
     * citable in AI search results.
     */
    case BlockAgents = 'block_agents';

    /** Blocks every known AI bot: training, search and assistants alike. */
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
            self::BlockAgents => array_merge(
                (array) config('ai_bots.training'),
                (array) config('ai_bots.agent'),
            ),
            self::BlockAll => array_merge(
                (array) config('ai_bots.training'),
                (array) config('ai_bots.search'),
                (array) config('ai_bots.agent'),
            ),
        };
    }
}
