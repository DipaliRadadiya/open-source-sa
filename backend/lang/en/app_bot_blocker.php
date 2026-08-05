<?php

/*
 * Copy for the AI Bot Blocker screen. Plain-language labels on purpose —
 * "training vs retrieval crawler" stays in the API/docs, never the button
 * the site owner actually reads.
 */
return [

    'policies' => [
        'allow_all' => [
            'title' => 'Allow all AI bots',
            'description' => 'No AI crawler is blocked.',
        ],
        'block_training' => [
            'title' => 'Block AI training bots',
            'description' => 'Stops bots that scrape your content to train AI models. AI search engines that send you visitors, like ChatGPT search and Perplexity, still work.',
        ],
        'block_all' => [
            'title' => 'Block all AI bots',
            'description' => 'Blocks every known AI bot, including ones that send you traffic from AI search results.',
        ],
    ],

];
