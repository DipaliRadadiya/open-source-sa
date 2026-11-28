<?php

/*
 * Which Node the installer puts on a new server.
 *
 * By default the newest LTS major nodejs.org lists on the day of the install, so a new server does
 * not start on an LTS that has already moved to maintenance. A pinned major is kept, and an
 * unreachable nodejs.org falls back to a known-good major instead of stopping the install.
 */

/**
 * resolve_node_version() with `curl` answering $index (null: it fails), from NODE_VERSION=$asked.
 *
 * @return array{code: int, out: string}
 */
function resolveNode(string $asked, ?string $index): array
{
    $curl = $index === null
        ? 'curl() { return 7; }'
        : 'curl() { printf "%s" '.escapeshellarg($index).'; }';

    $script = 'set -Eeuo pipefail
        '.installerFunctions('resolve_node_version').'
        warn() { echo "warn: $*"; }
        die() { echo "die: $*"; exit 1; }
        '.$curl.'
        NODE_VERSION_FALLBACK=24
        NODE_VERSION='.escapeshellarg($asked).'
        resolve_node_version
        echo "NODE=$NODE_VERSION"';

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** Newest first, as nodejs.org/dist/index.json is: a Current release ahead of the LTS ones. */
const NODE_INDEX = '[{"version":"v27.0.0","date":"2027-04-22","lts":false},'
    .'{"version":"v26.3.0","date":"2026-11-20","lts":"Lithium"},'
    .'{"version":"v24.12.0","date":"2026-11-18","lts":"Krypton"}]';

it('installs the newest LTS major by default, not the newest release', function () {
    expect(resolveNode('lts', NODE_INDEX)['out'])->toContain('NODE=26')->not->toContain('warn:');
});

it('defaults to lts', function () {
    expect(installerSource())->toContain('NODE_VERSION="${NODE_VERSION:-lts}"');
});

it('keeps a major the caller pinned, without asking nodejs.org', function () {
    expect(resolveNode('22', null)['out'])->toContain('NODE=22')->not->toContain('warn:');
});

it('falls back to the known-good major when nodejs.org cannot be reached', function () {
    $run = resolveNode('lts', null);

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('warn:')
        ->and($run['out'])->toContain('NODE=24');
});

it('falls back when the answer is not the index it expects', function () {
    expect(resolveNode('lts', '<html>blocked</html>')['out'])->toContain('NODE=24');
});

it('refuses a NODE_VERSION that is not a major or lts', function () {
    $run = resolveNode('24; rm -rf /', null);

    expect($run['code'])->toBe(1)->and($run['out'])->toContain('die:');
});
