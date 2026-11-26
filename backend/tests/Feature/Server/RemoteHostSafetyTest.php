<?php

use App\Rules\SafeProviderHost;
use App\Rules\SafeRemoteHost;
use App\Support\RemoteHost;

/**
 * The panel must refuse to connect to loopback and the cloud metadata address
 * however they are spelled.
 *
 * Resolvers, libcurl and the SSH and FTP clients read short, decimal, hex and octal
 * spellings as addresses via `inet_aton`, so a check that only range-tests dotted quads
 * lets them through as hostnames. `0251.0376.0251.0376` (octal for 169.254.169.254, the
 * cloud metadata address) is the one that matters most. FTP and SFTP never go through
 * Guzzle, so the panel's own check is the only protection there.
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
    // filter_var refuses it as an IP, but libcurl percent-decodes it to 127.0.0.1 and connects.
    'percent-encoded loopback' => ['127.0.0.%31'],
]);

/** Destinations people genuinely have, which must keep working. */
dataset('permitted hosts', [
    'a public name' => ['backup.example.com'],
    'a deep public name' => ['s3.eu-west-1.amazonaws.com'],
    // Private LAN is allowed on purpose: a NAS on the same network is a normal deployment.
    'a LAN address' => ['192.168.1.50'],
    'another LAN range' => ['10.0.0.5'],
    'a single-label LAN name' => ['nas'],
    'a name with a numeric label that is not the last' => ['127.0.0.1.nip.io'],
]);

it('refuses every spelling of a forbidden host on a bare host field', function (string $host) {
    expect(hostRuleRefuses(new SafeRemoteHost, $host))->toBeTrue();
})->with('forbidden hosts');

it('refuses every spelling of a forbidden host in an endpoint URL', function (string $host) {
    // Same rule set, different surface: the S3 endpoint and the self-hosted git URL.
    expect(hostRuleRefuses(new SafeProviderHost, 'https://'.$host))->toBeTrue();
})->with('forbidden hosts');

it('still accepts the destinations people actually have', function (string $host) {
    expect(hostRuleRefuses(new SafeRemoteHost, $host))->toBeFalse();
})->with('permitted hosts');

it('still accepts a legitimate endpoint URL', function (string $host) {
    expect(hostRuleRefuses(new SafeProviderHost, 'https://'.$host))->toBeFalse();
})->with('permitted hosts');

it('treats a trailing root dot as the same name', function () {
    // `example.com.` and `example.com` are one name; the canonicaliser removes the dot,
    // otherwise `localhost.` walks past the block.
    expect(hostRuleRefuses(new SafeRemoteHost, 'localhost.'))->toBeTrue();
    expect(RemoteHost::canonical('EXAMPLE.COM.'))->toBe('example.com');
});

it('refuses a host it cannot interpret rather than guessing at it', function () {
    // "Malformed" and "blocked" are different answers; an unknown spelling must land on
    // the first, which makes the check fail closed.
    expect(RemoteHost::isUninterpretable('0x7f000001'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('2130706433'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('has a space'))->toBeTrue()
        ->and(RemoteHost::isUninterpretable('backup.example.com'))->toBeFalse()
        // A canonical IP literal is interpretable — `isBlocked` decides it.
        ->and(RemoteHost::isUninterpretable('8.8.8.8'))->toBeFalse();
});

it('does not block a public address just because it is written as an IP', function () {
    // The rules block ranges, not the use of an address: a destination reachable only by IP is valid.
    expect(RemoteHost::isBlocked('8.8.8.8'))->toBeFalse()
        ->and(RemoteHost::isBlocked('203.0.113.10'))->toBeFalse();
});
