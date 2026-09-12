<?php

use App\Services\Server\Databases\PgHbaFile;

/*
 * The text half of PostgreSQL remote access, tested without a server.
 *
 * `pg_hba.conf` belongs to the cluster, not the panel — it can carry rules an
 * administrator wrote, a replication entry, an include directive. Everything
 * outside the markers has to survive byte for byte, and these are the tests
 * that say so.
 */

const DEFAULT_HBA = <<<'CONF'
# PostgreSQL Client Authentication Configuration File
local   all             postgres                                peer
local   all             all                                     peer
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
local   replication     all                                     peer

CONF;

it('writes a single-host rule as an explicit /32', function () {
    // PostgreSQL would accept the bare address, but the file should say what it
    // means to whoever reads it next.
    expect(PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'))
        ->toBe(['host "shop" "shop_user" 203.0.113.4/32 scram-sha-256']);
});

it('keeps a CIDR the user gave as-is', function () {
    expect(PgHbaFile::lines('shop', 'shop_user', '10.0.0.0/8'))
        ->toBe(['host "shop" "shop_user" 10.0.0.0/8 scram-sha-256']);
});

it('writes both address families for anywhere', function () {
    // MySQL's `%` covers every family in one grant. 0.0.0.0/0 is IPv4 only, so
    // one line would leave a user told "anywhere" unable to connect over IPv6
    // with no setting to blame.
    expect(PgHbaFile::lines('shop', 'shop_user', '%'))->toBe([
        'host "shop" "shop_user" 0.0.0.0/0 scram-sha-256',
        'host "shop" "shop_user" ::0/0 scram-sha-256',
    ]);
});

it('quotes names so a database called all cannot become the keyword', function () {
    // Unquoted, `all` in pg_hba.conf means every database on the cluster — so
    // a database legitimately named `all` would silently grant far more than
    // the one it was asked for.
    expect(PgHbaFile::lines('all', 'replication', '203.0.113.4'))
        ->toBe(['host "all" "replication" 203.0.113.4/32 scram-sha-256']);
});

it('appends the block and leaves the original file untouched', function () {
    $rules = [PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4')];

    $result = PgHbaFile::render(DEFAULT_HBA, $rules);

    // Every original line survives, in order.
    foreach (preg_split('/\r?\n/', trim(DEFAULT_HBA)) as $line) {
        expect($result)->toContain($line);
    }

    expect($result)->toContain(PgHbaFile::BEGIN)
        ->and($result)->toContain(PgHbaFile::END)
        // Appended, not prepended: first match wins in pg_hba.conf, so a block
        // at the top would override rules an administrator wrote deliberately.
        ->and(strpos($result, PgHbaFile::BEGIN))->toBeGreaterThan(strpos($result, 'local   replication'));
});

it('edits the block on a second write instead of appending another', function () {
    $first = PgHbaFile::render(DEFAULT_HBA, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'),
    ]);

    $second = PgHbaFile::render($first, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '198.51.100.9'),
    ]);

    expect(substr_count($second, PgHbaFile::BEGIN))->toBe(1)
        ->and($second)->toContain('198.51.100.9/32')
        ->and($second)->not->toContain('203.0.113.4/32');
});

it('reads back the rules it wrote', function () {
    $contents = PgHbaFile::render(DEFAULT_HBA, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '%'),
        PgHbaFile::key('blog', 'blog_user') => PgHbaFile::lines('blog', 'blog_user', '10.0.0.1'),
    ]);

    $rules = PgHbaFile::rules($contents);

    expect($rules)->toHaveKeys(['shop|shop_user', 'blog|blog_user'])
        ->and($rules['shop|shop_user'])->toHaveCount(2)
        ->and($rules['blog|blog_user'])->toHaveCount(1);
});

it('never reads a rule from outside the block', function () {
    // The administrator's own `host all all 127.0.0.1/32` line is not ours to
    // manage, report, or rewrite.
    expect(PgHbaFile::rules(DEFAULT_HBA))->toBe([]);
});

it('removes the block entirely when the last rule goes', function () {
    $withRule = PgHbaFile::render(DEFAULT_HBA, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'),
    ]);

    $cleared = PgHbaFile::render($withRule, []);

    expect($cleared)->not->toContain(PgHbaFile::BEGIN)
        ->and($cleared)->not->toContain('203.0.113.4')
        // And the file is back to what it was, not merely block-free.
        ->and(trim($cleared))->toBe(trim(DEFAULT_HBA));
});

it('drops one account without touching the others', function () {
    $contents = PgHbaFile::render(DEFAULT_HBA, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'),
        PgHbaFile::key('blog', 'blog_user') => PgHbaFile::lines('blog', 'blog_user', '198.51.100.9'),
    ]);

    $rules = PgHbaFile::rules($contents);
    unset($rules[PgHbaFile::key('shop', 'shop_user')]);

    $result = PgHbaFile::render($contents, $rules);

    expect($result)->not->toContain('203.0.113.4')
        ->and($result)->toContain('198.51.100.9')
        ->and($result)->toContain('host    all             all             127.0.0.1/32            scram-sha-256');
});

it('preserves an administrator\'s own additions across a rewrite', function () {
    $custom = DEFAULT_HBA."\nhost    all             admin           192.0.2.0/24            cert\n";

    $result = PgHbaFile::render($custom, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'),
    ]);

    expect($result)->toContain('host    all             admin           192.0.2.0/24            cert');

    // And still there after the block is cleared again.
    expect(PgHbaFile::render($result, []))
        ->toContain('host    all             admin           192.0.2.0/24            cert');
});
