<?php

use App\Services\Server\WebServers\OlsDriver;

/**
 * The OpenLiteSpeed capability notes must name the feature the code actually
 * refuses.
 *
 * They did not. README.md and install.sh both told the user "the bot blocker is
 * not available on OpenLiteSpeed" — while both OLS vhost templates render the
 * bot rules, and the feature the driver really refuses is the WAF. The wording
 * came from `OlsDriver::supportsWaf()`'s own docblock, relabelled.
 *
 * The cost is exact and backwards: somebody avoids OLS to keep bot blocking
 * they would have had, and picks it unaware the WAF will refuse. Nothing tied
 * the sentence to the code, so nothing caught it.
 *
 * This is the same shape as the sudoers-vs-install.sh guards in
 * PrivilegedBinaryCoverageTest: a claim written in one place about behaviour
 * defined in another, checked rather than remembered.
 */
function olsCapabilityDocs(): array
{
    return [
        'README.md' => (string) file_get_contents(base_path('../README.md')),
        'install.sh' => (string) file_get_contents(base_path('../install.sh')),
    ];
}

it('does not tell anyone the bot blocker is unavailable on OpenLiteSpeed', function () {
    // Guarded by what the templates actually do, so this test stops applying
    // by itself if the bot rules are ever removed from them.
    foreach (['php', 'node'] as $template) {
        expect(file_get_contents(resource_path("views/server/vhosts/openlitespeed/{$template}.blade.php")))
            ->toContain('botBlock');
    }

    foreach (olsCapabilityDocs() as $file => $contents) {
        expect(mb_strtolower($contents))
            ->not->toContain('bot blocker is not available')
            ->not->toContain('bot blocker cannot be enforced');
    }
});

it('names the WAF as the OpenLiteSpeed capability gap, because that is the one', function () {
    expect(app(OlsDriver::class)->supportsWaf())->toBeFalse();

    // Both places a user is told before they choose the stack: the file they
    // read and the prompt they answer.
    foreach (olsCapabilityDocs() as $file => $contents) {
        expect($contents)->toContain('WAF');
    }
});
