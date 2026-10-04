<?php

use App\Rules\Hostname;
use Illuminate\Support\Facades\Validator;

/*
 * Bug #9: creating a site, staging or clone accepted `-bad-.example.com` and
 * `qa..example.com`; bug #11: the same pattern refused a punycode top-level
 * domain. One rule for every place a site's hostname is entered.
 */

function hostnameErrors(string $value): array
{
    return Validator::make(['domain' => $value], ['domain' => [new Hostname]])->errors()->get('domain');
}

it('accepts ordinary and punycode hostnames', function (string $domain) {
    expect(hostnameErrors($domain))->toBe([]);
})->with([
    'example.com',
    'shop.example.co.uk',
    'a-b.example.com',
    'xn--80ak6aa92e.com',
    'example.xn--p1ai',
    '1password.com',
    str_repeat('a', 63).'.com',
]);

it('refuses what is not a hostname', function (string $domain) {
    expect(hostnameErrors($domain))->toBe([__('errors/application.invalid_domain')]);
})->with([
    'a leading hyphen' => ['-bad-.example.com'],
    'only a leading hyphen' => ['-bad.example.com'],
    'a trailing hyphen' => ['bad-.example.com'],
    'an empty label' => ['qa..example.com'],
    'a leading dot' => ['.example.com'],
    'a trailing dot' => ['example.com.'],
    'one label' => ['localhost'],
    'a label over 63' => [str_repeat('a', 64).'.com'],
    'over 253 in all' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.com'],
    'a digit TLD' => ['example.c0m'],
    'a one-letter TLD' => ['example.c'],
    'an underscore' => ['my_site.example.com'],
    'a space' => ['my site.com'],
]);

it('names an IP address as one', function () {
    expect(hostnameErrors('23.172.120.86'))->toBe([__('errors/application.domain_is_ip')]);
});
