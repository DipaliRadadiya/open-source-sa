<?php

use App\Rules\SafeProviderHost;
use App\Rules\SafeRemoteHost;
use App\Support\RemoteHost;

/**
 * The panel must refuse to connect to loopback and the cloud metadata address
 * however they are spelled.
 *
 * Both rules used to range-check a host *only* when `filter_var` accepted it as
 * a dotted quad. Every other spelling of an address failed that test, fell
 * through to the hostname branch, and was accepted — while the resolver,
 * libcurl and the SSH and FTP clients all read them as addresses via
 * `inet_aton`, which takes short, decimal, hex and octal forms.
 *
 * `0251.0376.0251.0376` is the one that matters most: it is octal for
 * 169.254.169.254, the cloud metadata address, which is the single target
 * these rules exist to deny.
 *
 * Prompted by CVE-2026-69246 (Guzzle). Upgrading Guzzle does not fix this —
 * the advisory says these spellings "remain accepted after the patch" — and
 * FTP and SFTP never went through Guzzle at all, so for those the panel's own
 * check was the only thing standing there.
 */
function hostRuleRefuses(object $rule, string $value): bool
{
    $failed = false;

    $rule->validate('host', $value, function () use (&$failed) {
        $failed = true;

        return new class
        {
            public function translate(): void {}
        };
    });

    return $failed;
}

/** Every spelling that reaches somewhere the panel must not go. */
dataset('forbidden hosts', [
    'dotted-quad loopback' => ['127.0.0.1'],
    'the name' => ['localhost'],
    'a subdomain of it' => ['sub.localhost'],
    'short form' => ['127.1'],
    'one decimal integer' => ['2130706433'],
    'hex' => ['0x7f000001'],
    'octal' => ['0177.0.0.1'],
    'mixed hex and short' => ['0x7f.1'],
    'cloud metadata' => ['169.254.169.254'],
    'cloud metadata in octal' => ['0251.0376.0251.0376'],
    'the zero network' => ['0.0.0.0'],
    'IPv6 loopback' => ['::1'],
    'IPv6 loopback, written out' => ['0:0:0:0:0:0:0:1'],
    'IPv4 loopback mapped into IPv6' => ['::ffff:127.0.0.1'],
    'link-local' => ['fe80::1'],
    // The advisory's own example: filter_var refuses it as an IP, libcurl
    // percent-decodes it to 127.0.0.1 and connects.
    'percent-encoded loopback' => ['127.0.0.%31'],
]);

/** Destinations people genuinely have, which must keep working. */
dataset('permitted hosts', [
    'a public name' => ['backup.example.com'],
    'a deep public name' => ['s3.eu-west-1.amazonaws.com'],
    // Private LAN is allowed on purpose: a NAS on the same network is the
    // normal deployment for this feature.
    'a LAN address' => ['192.168.1.50'],
    'another LAN range' => ['10.0.0.5'],
    'a single-label LAN name' => ['nas'],
    'a name with a numeric label that is not the last' => ['127.0.0.1.nip.io'],
]);

it('refuses every spelling of a forbidden host on a bare host field', function (string $host) {
    expect(hostRuleRefuses(new SafeRemoteHost, $host))->toBeTrue();
})->with('forbidden hosts');

it('refuses every spelling of a forbidden host in an endpoint URL', function (string $host) {
    // Same rule set, different surface: this one guards the S3 endpoint and
    // the self-hosted git URL.
    expect(hostRuleRefuses(new SafeProviderHost, 'https://'.$host))->toBeTrue();
})->with('forbidden hosts');

it('still accepts the destinations people actually have', function (string $host) {
    expect(hostRuleRefuses(new SafeRemoteHost, $host))->toBeFalse();
})->with('permitted hosts');

it('still accepts a legitimate endpoint URL', function (string $host) {
    expect(hostRuleRefuses(new SafeProviderHost, 'https://'.$host))->toBeFalse();
})->with('permitted hosts');

it('treats a trailing root dot as the same name', function () {
    // `example.com.` and `example.com` are one name. Only one of them would
    // match a suffix check, so the canonicaliser removes the dot — otherwise
    // `localhost.` walks straight past the block.
    expect(hostRuleRefuses(new SafeRemoteHost, 'localhost.'))->toBeTrue();
    expect(RemoteHost::canonical('EXAMPLE.COM.'))->toBe('example.com');
});

it('refuses a host it cannot interpret rather than guessing at it', function () {
    // The distinction the rules draw: "malformed" and "blocked" are different
    // answers, and a spelling nobody has thought of yet must land on the first
    // one. This is what makes the check fail closed.
    expect(RemoteHost::isUninterpretable('0x7f000001'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('2130706433'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('has a space'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('backup.example.com'))->toBeFalse()
        // A canonical IP literal is interpretable — `isBlocked` decides it.
        ->and(RemoteHost::isUninterpretable('8.8.8.8'))->toBeFalse();
});

it('does not block a public address just because it is written as an IP', function () {
    // The rules block ranges, not the act of using an address. A destination
    // reachable only by IP is a real configuration.
    expect(RemoteHost::isBlocked('8.8.8.8'))->toBeFalse()
        ->and(RemoteHost::isBlocked('203.0.113.10'))->toBeFalse();
});
