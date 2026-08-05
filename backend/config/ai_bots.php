<?php

/*
 * AI crawler names, split by what they're for — not a flat "block every AI
 * bot" list. Training crawlers feed model weights and never send a visitor
 * back; retrieval/citation crawlers fetch a page to answer a live question
 * and are how a site gets cited in ChatGPT search, Perplexity and similar —
 * blocking those costs the site owner real traffic, so "Block AI training
 * bots" only touches the first list.
 *
 * Curated, not exhaustive — cross-checked against multiple independent 2026
 * sources rather than importing a third-party "block everything AI" list
 * wholesale (most of those also block the retrieval bots above, which is
 * exactly the traffic this project's default is trying to protect). Expect
 * this to need occasional updates as crawlers split or rename, the way
 * Anthropic split ClaudeBot into training and retrieval bots in Q2 2026.
 *
 * Last reviewed: 2026-08-05.
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

    // Fetches a page to answer one live question and, for the "-User" and
    // "SearchBot" agents, is how a site earns a citation in an AI answer.
    // Left out of "Block AI training bots" on purpose.
    'retrieval' => [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-Web',
        'Claude-User',
        'Claude-SearchBot',
        'Perplexity-User',
        'PerplexityBot',
    ],

];
