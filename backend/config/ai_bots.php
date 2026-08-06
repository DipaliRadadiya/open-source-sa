<?php

/*
 * AI crawler names in three buckets, because "AI bot" is three different
 * things and blocking them is three different decisions:
 *
 *  - `training` feeds model weights and never sends a visitor back.
 *  - `search` indexes the site so it can be *cited* in an AI answer. This is
 *    inbound traffic; blocking it is what costs a site owner real money.
 *  - `agent` fetches one page because a person asked a question right now.
 *    It carries the load of a crawl without the citation of a search index,
 *    so wanting it gone while keeping citations is a coherent position — and
 *    one two buckets could not express.
 *
 * The split follows the industry: Anthropic split ClaudeBot into training and
 * retrieval agents in Q2 2026, and Cloudflare moved its own policy from two
 * categories to Search/Agent/Training in July 2026. Treating training and
 * search as one bucket is the documented expensive mistake.
 *
 * Curated, not exhaustive — cross-checked against multiple independent 2026
 * sources rather than importing a third-party "block everything AI" list
 * wholesale (most of those also block the search bots, which is exactly the
 * traffic this project's default is trying to protect). Expect this to need
 * occasional updates as crawlers split or rename.
 *
 * Last reviewed: 2026-08-06.
 */

return [

    'training' => [
        'GPTBot',
        'ClaudeBot',
        'Google-Extended',
        'CCBot',
        'Bytespider',
        'Meta-ExternalAgent',
        'meta-externalagent',
        'Applebot-Extended',
        'Amazonbot',
        'anthropic-ai',
        'cohere-ai',
        'Diffbot',
        'FacebookBot',
        'ImagesiftBot',
        'omgili',
        'omgilibot',
        'PetalBot',
        'Timpibot',
        'YouBot',
        'AI2Bot',
        'Crawlspace',
        'ICC-Crawler',
        'SemrushBot-OCOB',
    ],

    // Indexes the site to answer questions about it later — the crawlers
    // behind ChatGPT search, Claude search and Perplexity citations. Blocking
    // these removes the site from AI search results, so nothing but the
    // explicit "block everything" choice touches them.
    'search' => [
        'OAI-SearchBot',
        'Claude-SearchBot',
        'PerplexityBot',
        // The search-side counterpart to `Amazonbot`, which sits in training.
        'Amzn-SearchBot',
    ],

    // Acts in real time on one person's behalf: a chat assistant fetching the
    // page a user just asked about. Costs a request, returns no citation.
    'agent' => [
        'ChatGPT-User',
        'Claude-User',
        // Anthropic's older retrieval agent, kept for sites still seeing it.
        'Claude-Web',
        'Perplexity-User',
    ],

];
