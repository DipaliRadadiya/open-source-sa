<?php

namespace App\Services\Server\Databases;

/**
 * The panel's block inside somebody else's `pg_hba.conf`.
 *
 * Pure text in, pure text out (no I/O), so it can be tested without a PostgreSQL server.
 *
 * A marked block, not a rewritten file: `pg_hba.conf` is the cluster's and may carry an
 * administrator's rules. Everything outside the markers is copied through byte for byte, and a
 * re-run edits the block instead of appending a second one (same convention as
 * {@see Installers\MongoDbInstaller}).
 *
 * Appended at the end, deliberately: PostgreSQL takes the first matching record, so a block at
 * the top would override an administrator's rules, including a deliberate `reject`. A shadowed
 * rule of ours is visible in `pg_hba_file_rules`; an overridden deny is visible nowhere.
 *
 * `include_dir` is not usable: it needs PostgreSQL 16+, and Ubuntu 22.04 ships 14.
 */
final class PgHbaFile
{
    /**
     * Neutral wording, deliberately: the panel is white-labelled and this is written onto a
     * customer's server, so no brand name here (`WhiteLabelTest` enforces it).
     */
    public const BEGIN = '# BEGIN control panel managed rules — edits inside this block are overwritten';

    public const END = '# END control panel managed rules';

    /**
     * The rules currently in the block, keyed by `database|role`.
     *
     * Keyed so a write replaces one account's rules and leaves every other account's alone.
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

            // `host <database> <role> <address> <method>` is the shape this class writes; anything
            // else in the block is dropped on the next write, as the marker warns.
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
     * An empty rule set removes the block entirely instead of leaving an empty one.
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
     * `anywhere` becomes two rules: `0.0.0.0/0` is IPv4 only, so IPv6 clients need `::0/0`
     * (MySQL's `%` covers both).
     *
     * The method is `scram-sha-256`; cleartext `password` is never written.
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
     * PostgreSQL treats `203.0.113.4` as `/32`; the explicit mask matches what the panel stores.
     */
    private static function cidr(string $host): string
    {
        return str_contains($host, '/') ? $host : $host.'/32';
    }

    /**
     * Names are double-quoted: unquoted, `all` and `replication` are keywords, so a database
     * called `all` would silently grant access to every database on the cluster.
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
