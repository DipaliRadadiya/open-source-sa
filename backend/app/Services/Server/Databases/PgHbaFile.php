<?php

namespace App\Services\Server\Databases;

/**
 * The panel's block inside somebody else's `pg_hba.conf`.
 *
 * Pure text in, pure text out — no I/O at all, so the part of this feature most
 * likely to corrupt a file somebody depends on can be tested exhaustively
 * without a PostgreSQL server anywhere near it.
 *
 * **A marked block, not a rewritten file.** `pg_hba.conf` is the cluster's, not
 * ours: it may carry rules an administrator wrote, a replication entry, an
 * `include` directive. Everything outside the markers is copied through byte for
 * byte, and a re-run edits the block rather than appending a second one — the
 * same convention {@see Installers\MongoDbInstaller} uses for the mongod config.
 *
 * **Appended at the end, deliberately.** PostgreSQL takes the *first* matching
 * record and never falls through, so a block at the top would take precedence
 * over everything an administrator had already written — including a deliberate
 * `reject`. Silently overriding somebody's explicit deny is worse than the
 * alternative, which is that our rule can be shadowed by one. A shadowed rule is
 * visible in `pg_hba.conf` and in `pg_hba_file_rules`; an overridden deny is
 * visible nowhere.
 *
 * `include_dir` would be tidier and is not available: it is PostgreSQL 16+, and
 * Ubuntu 22.04 — a release this panel supports — ships PostgreSQL 14.
 */
final class PgHbaFile
{
    /**
     * Neutral wording, deliberately.
     *
     * The panel is white-labelled and this string is written onto a customer's
     * server, where a reseller's client should see their reseller's product or
     * nothing at all. `MongoDbInstaller::CONFIG_MARKER` says "Managed by the
     * control panel" for the same reason, and `WhiteLabelTest` fails the build
     * over a brand name in here — which is how the first draft of this constant
     * was caught.
     */
    public const BEGIN = '# BEGIN control panel managed rules — edits inside this block are overwritten';

    public const END = '# END control panel managed rules';

    /**
     * The rules currently in the block, keyed by `database|role`.
     *
     * Keyed rather than listed because every write is an upsert for one
     * account: changing a user's address must replace that account's rules and
     * leave every other account's alone.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $contents): array
    {
        $block = self::block($contents);
        $rules = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // `host <database> <role> <address> <method>` — the shape this
            // class writes. Anything else in the block is something we did not
            // put there and is dropped on the next write, which is what the
            // marker warns about.
            $fields = preg_split('/\s+/', $line) ?: [];

            if (count($fields) < 5 || $fields[0] !== 'host') {
                continue;
            }

            $rules[self::key($fields[1], $fields[2])][] = $line;
        }

        return $rules;
    }

    /**
     * `$contents` with the block replaced by `$rules`.
     *
     * An empty rule set removes the block entirely rather than leaving an empty
     * one: a file that has never had remote access should look like a file that
     * has never had remote access.
     *
     * @param  array<string, array<int, string>>  $rules
     */
    public static function render(string $contents, array $rules): string
    {
        $without = rtrim(self::withoutBlock($contents), "\n");

        $lines = [];

        foreach ($rules as $ruleLines) {
            foreach ($ruleLines as $line) {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return $without === '' ? '' : $without."\n";
        }

        return $without."\n\n".self::BEGIN."\n".implode("\n", $lines)."\n".self::END."\n";
    }

    /**
     * The `host` lines granting one role access to one database from `$host`.
     *
     * `anywhere` becomes **two** rules, not one. MySQL's `%` covers every
     * address family in a single grant; `0.0.0.0/0` is IPv4 only, and on a
     * server with IPv6 — which is most of them — a user told "anywhere" would
     * find half the internet unable to connect and no setting to blame.
     *
     * `scram-sha-256` is the method: it is the default for a cluster this panel
     * installed, and `md5` is long deprecated. `password` (cleartext) is never
     * written.
     *
     * @return array<int, string>
     */
    public static function lines(string $database, string $role, string $host): array
    {
        $addresses = $host === '%' ? ['0.0.0.0/0', '::0/0'] : [self::cidr($host)];

        return array_map(
            fn (string $address): string => sprintf(
                'host %s %s %s scram-sha-256',
                self::quote($database),
                self::quote($role),
                $address,
            ),
            $addresses,
        );
    }

    public static function key(string $database, string $role): string
    {
        return trim($database, '"').'|'.trim($role, '"');
    }

    /**
     * A bare address becomes an explicit single-host CIDR.
     *
     * PostgreSQL accepts `203.0.113.4` and treats it as `/32`, but writing the
     * mask makes the file say what it means to somebody reading it later, and
     * matches what the panel stores.
     */
    private static function cidr(string $host): string
    {
        return str_contains($host, '/') ? $host : $host.'/32';
    }

    /**
     * Database and role names are double-quoted in `pg_hba.conf` for the same
     * reason they are in SQL: unquoted, `all` and `replication` are keywords
     * rather than names, so a database legitimately called `all` would silently
     * grant access to every database on the cluster.
     */
    private static function quote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private static function block(string $contents): string
    {
        $start = strpos($contents, self::BEGIN);
        $end = strpos($contents, self::END);

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return substr($contents, $start + strlen(self::BEGIN), $end - $start - strlen(self::BEGIN));
    }

    private static function withoutBlock(string $contents): string
    {
        $start = strpos($contents, self::BEGIN);
        $end = strpos($contents, self::END);

        if ($start === false || $end === false || $end < $start) {
            return $contents;
        }

        return rtrim(substr($contents, 0, $start), "\n")."\n".ltrim(substr($contents, $end + strlen(self::END)), "\n");
    }
}
