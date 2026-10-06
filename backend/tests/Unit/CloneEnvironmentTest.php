<?php

use App\Services\Server\Applications\Cloning\CloneEnvironment;

/*
| CLN-01: a git clone's `.env`, pointed at the clone's database and domain.
| Values are matched exactly; everything else is left byte for byte.
*/

function rewriteEnv(string $env): string
{
    return CloneEnvironment::rewrite($env, ['shop_db' => 'clone_db', 'shop_u' => 'clone_u', 'p@ss#1' => 'N3w.pw'], 'shop.test', 'copy.test');
}

it('replaces whole values only, in any quoting', function () {
    expect(rewriteEnv("DB_DATABASE=shop_db\nDB_USERNAME='shop_u'\nDB_PASSWORD=\"p@ss#1\"\nexport PGDATABASE=shop_db\n"))
        ->toBe("DB_DATABASE=clone_db\nDB_USERNAME='clone_u'\nDB_PASSWORD=\"N3w.pw\"\nexport PGDATABASE=clone_db\n");
});

it('leaves a value that only contains a source value alone', function () {
    $env = "BACKUP_DB=shop_db_old # nightly\nNAME=\"shop_u team\"\nEMPTY=\"\"\n# DB_DATABASE=shop_db\n";

    expect(rewriteEnv($env))->toBe($env);
});

it('rewrites a connection URL and the site URL, and nothing else that is a URL', function () {
    expect(rewriteEnv("DATABASE_URL=postgresql://shop_u:p%40ss%231@127.0.0.1:5432/shop_db?schema=public\nAPP_URL=https://shop.test/\nAPI=https://api.shop.test\nCDN=https://cdn.example.com/shop_db\n"))
        ->toBe("DATABASE_URL=postgresql://clone_u:N3w.pw@127.0.0.1:5432/clone_db?schema=public\nAPP_URL=https://copy.test/\nAPI=https://api.shop.test\nCDN=https://cdn.example.com/shop_db\n");
});

it('does not respell a URL that needed no change', function () {
    $env = "REDIS_URL=redis://:p%2Fx@127.0.0.1:6379/0\n";

    expect(rewriteEnv($env))->toBe($env);
});
