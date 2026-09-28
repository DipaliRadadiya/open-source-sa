<?php

/**
 * The bot-blocker rule has to match the User-Agent crawlers really send.
 *
 * nginx and Apache anchored it — `^(GPTBot|ClaudeBot|…)` — so it matched only
 * a User-Agent that *starts* with the bot's name. The real ones do not: they
 * start with `Mozilla/5.0` and name themselves inside the "compatible"
 * comment. On the Apache test box, `block_training` let GPTBot straight
 * through while a bare `GPTBot/1.2` was refused. OpenLiteSpeed's rule was
 * unanchored and worked.
 *
 * Read from each template's source, with the placeholder filled the way the
 * driver fills it (`preg_quote`, joined by `|`), and run as PCRE — which is
 * what all three servers use for these rules.
 */
function botBlockRules(): array
{
    $rules = [];

    foreach (glob(base_path('resources/views/server/vhosts/*/*.blade.php')) ?: [] as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (! str_contains($line, '{{ $botBlock }}')) {
                continue;
            }

            // The regex is the parenthesised group around the placeholder,
            // plus whatever anchors sit directly against it.
            preg_match('/(\S*\(\{\{ \$botBlock \}\}\)\S*?)(?:"|\s|$)/', $line, $matches);
            $rules[str_replace(base_path('resources/views/server/vhosts/'), '', $path)] = trim($matches[1] ?? '', '"');
        }
    }

    return $rules;
}

it('finds a bot rule in every web server', function () {
    expect(implode(' ', array_keys(botBlockRules())))
        ->toContain('nginx/')
        ->toContain('apache/')
        ->toContain('openlitespeed/');
});

it('matches the User-Agent the crawlers really send', function (string $userAgent) {
    $pattern = implode('|', array_map(fn (string $bot) => preg_quote($bot, '/'), ['GPTBot', 'ClaudeBot', 'Amazonbot', 'Bytespider', 'CCBot']));

    foreach (botBlockRules() as $template => $rule) {
        $regex = '/'.str_replace('{{ $botBlock }}', $pattern, $rule).'/i';

        expect(preg_match($regex, $userAgent))->toBe(1, "{$template} lets through: {$userAgent}");
    }
})->with([
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/8.0.2 Safari/600.2.5 (Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)',
    'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
    'CCBot/2.0 (https://commoncrawl.org/faq/)',
]);

it('leaves an ordinary browser alone', function () {
    $pattern = implode('|', array_map(fn (string $bot) => preg_quote($bot, '/'), ['GPTBot', 'ClaudeBot', 'Amazonbot', 'Bytespider', 'CCBot']));

    foreach (botBlockRules() as $template => $rule) {
        $regex = '/'.str_replace('{{ $botBlock }}', $pattern, $rule).'/i';

        expect(preg_match($regex, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'))
            ->toBe(0, $template);
    }
});
