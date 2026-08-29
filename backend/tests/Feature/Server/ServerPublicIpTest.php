<?php

use App\Services\Server\ServerPublicIp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The address the internet reaches this server on.
 *
 * The dashboard showed `hostname -I`'s first address and called it the
 * server's IP. On a VPS with a directly attached public address that is right;
 * behind NAT — AWS, GCP, Azure, anything with a floating IP — it is the
 * private address, and the panel was confidently offering `10.0.0.5` as the
 * thing to point DNS at. A tester hit exactly that.
 */
beforeEach(function () {
    Cache::flush();
    config(['server.metadata_base' => 'http://169.254.169.254', 'server.metadata_timeout' => 1]);
});

it('trusts the route table when it already answers a public address', function () {
    // Nothing on the network can improve on what the machine can see about
    // itself, so a public route answer must not cost a metadata request.
    Http::fake();

    $ip = app(ServerPublicIp::class)->detect(fn () => '203.0.113.10');

    expect($ip)->toBe('203.0.113.10');

    Http::assertNothingSent();
});

it('asks the cloud metadata service when the machine only sees a private address', function () {
    // The NAT case, and the reported one. `DnsVerifier::serverIp()` returns
    // null there by design — it refuses to report a private address as the
    // server's own — so without this the dashboard had nothing to show.
    Http::fake([
        '169.254.169.254/latest/meta-data/public-ipv4' => Http::response('198.51.100.7'),
        '*' => Http::response('', 404),
    ]);

    expect(app(ServerPublicIp::class)->detect(fn () => null))->toBe('198.51.100.7');
});

it('tries the other providers when the first does not answer', function () {
    // One link-local address, five different shapes behind it.
    Http::fake([
        '169.254.169.254/latest/meta-data/public-ipv4' => Http::response('', 404),
        '169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('198.51.100.9'),
        '*' => Http::response('', 404),
    ]);

    expect(app(ServerPublicIp::class)->detect(fn () => null))->toBe('198.51.100.9');
});

it('refuses a private address from the metadata service', function () {
    // Returning it would be the same wrong answer in a new place — the whole
    // reason the route-table source is not trusted when it answers privately.
    Http::fake(['*' => Http::response('10.0.0.5')]);

    expect(app(ServerPublicIp::class)->detect(fn () => null))->toBeNull();
});

it('refuses anything that is not an IPv4 address', function () {
    // A metadata service answering with an HTML error page has not told us
    // the public IP.
    Http::fake(['*' => Http::response('<html><body>Not Found</body></html>')]);

    expect(app(ServerPublicIp::class)->detect(fn () => null))->toBeNull();
});

it('answers null on a machine with no metadata service at all', function () {
    // Bare metal behind a hardware NAT genuinely cannot know its own public
    // address. Null is the honest answer and the caller renders "could not
    // determine" — not a blank that reads like a bug.
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(app(ServerPublicIp::class)->detect(fn () => null))->toBeNull();
});

it('does not ask twice within the cache window', function () {
    Http::fake(['*' => Http::response('198.51.100.7')]);

    $service = app(ServerPublicIp::class);

    expect($service->detect(fn () => null))->toBe('198.51.100.7')
        ->and($service->detect(fn () => null))->toBe('198.51.100.7');

    // A dashboard poll must not cost a metadata round trip every time.
    Http::assertSentCount(1);
});
