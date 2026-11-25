<?php

/*
 * AI crawler names in three buckets, because blocking them is three different decisions:
 *
 *  - `training` feeds model weights and never sends a visitor back.
 *  - `search` indexes the site so it can be *cited* in an AI answer. This is
 *    inbound traffic; blocking it costs a site owner real visitors.
 *  - `agent` fetches one page because a person asked a question right now.
 *    It carries the load of a crawl without the citation of a search index.
 *
 * Curated, not exhaustive: a third-party "block everything AI" list also blocks the search
 * bots, which is the traffic the default protects. Update as crawlers split or rename.
 */

return [

    'training' => [
        'GPTBot',
        'ClaudeBot',
        'CCBot',
        'Bytespider',
        'Meta-ExternalAgent',
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

    // Indexes the site for AI search answers and citations. Blocking these removes the site from
    // them, so only the explicit "block everything" choice touches them.
    'search' => [
        'OAI-SearchBot',
        'Claude-SearchBot',
        'PerplexityBot',
        // The search-side counterpart to `Amazonbot`, which sits in training.
        'Amzn-SearchBot',
    ],

    // Acts in real time on one person's behalf (a page a user just asked about).
    'agent' => [
        'ChatGPT-User',
        'Claude-User',
        // Anthropic's older retrieval agent, kept for sites still seeing it.
        'Claude-Web',
        'Perplexity-User',
    ],

    /*
     * Names that exist only inside robots.txt, never as a visitor's user agent: how a site says
     * "index me, don't train on me" to Google and Apple. A user-agent block can never match
     * them, so the bot blocker returns robots.txt lines for them instead.
     */
    'robots_only' => [
        'Google-Extended',
        'Applebot-Extended',
    ],

    /*
     * Real visitors and search engines, as they identify themselves.
     *
     * A block rule matches anywhere in the user agent, so "Always block" refuses anything found
     * in one of these (`Chrome`, `Windows`, `Mozilla/5.0`, `Googlebot/2.1` would block every
     * real visitor or Google). Keyed by what the refusal names.
     */
    'real_visitors' => [
        'Chrome (Windows)' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'Edge (Windows)' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
        'Firefox (Linux)' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Safari (macOS)' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
        'Safari (iPhone)' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Chrome (Android)' => 'Mozilla/5.0 (Linux; Android 14; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
        'Samsung Internet (Android)' => 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'Opera (Windows)' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0',
        'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Googlebot (smartphone)' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.6668.70 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'DuckDuckBot' => 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
        'YandexBot' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        'Baiduspider' => 'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
        'Applebot' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
    ],
];
