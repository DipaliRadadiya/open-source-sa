<?php

namespace App\Services\Server\Applications\Cloning;

/**
 * A git site's `.env`, pointed at its clone instead of its source (CLN-01).
 *
 * Values only, matched exactly: a value that IS the source's database name,
 * user or password becomes the clone's. Keys are not guessed — DB_DATABASE,
 * MYSQL_DB, PGDATABASE all hold the same thing, and the value is what says
 * which database it is. A connection URL (`DATABASE_URL`, Prisma, Mongo) has
 * its user, password and database replaced the same way, and the source's
 * domain becomes the clone's wherever it is a URL's host (`APP_URL`).
 *
 * Everything else is left byte for byte: comments, order, every other key.
 */
class CloneEnvironment
{
    /**
     * @param  array<string, string>  $replace  source value => clone value
     */
    public static function rewrite(string $contents, array $replace, string $fromDomain, string $toDomain): string
    {
        $replace = array_filter($replace, fn ($to, $from) => $from !== '' && $to !== '', ARRAY_FILTER_USE_BOTH);

        return (string) preg_replace_callback(
            '/^(\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_.]*\s*=\s*)(?:"([^"\n]*)"|\'([^\'\n]*)\'|([^\s#\'"]*))/m',
            function (array $m) use ($replace, $fromDomain, $toDomain): string {
                [$quote, $value] = match (true) {
                    $m[2] !== null => ['"', $m[2]],
                    $m[3] !== null => ["'", $m[3]],
                    default => ['', (string) $m[4]],
                };

                $value = array_key_exists($value, $replace)
                    ? $replace[$value]
                    : self::url($value, $replace, $fromDomain, $toDomain);

                return $m[1].$quote.$value.$quote;
            },
            $contents,
            flags: PREG_UNMATCHED_AS_NULL,
        );
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function url(string $value, array $replace, string $fromDomain, string $toDomain): string
    {
        if (! str_contains($value, '://')) {
            return $value;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $value;
        }

        $authority = '';
        $changed = false;

        if (isset($parts['user'])) {
            $user = rawurldecode($parts['user']);
            $changed = $changed || isset($replace[$user]);
            $authority = rawurlencode($replace[$user] ?? $user);

            if (isset($parts['pass'])) {
                $pass = rawurldecode($parts['pass']);
                $changed = $changed || isset($replace[$pass]);
                $authority .= ':'.rawurlencode($replace[$pass] ?? $pass);
            }

            $authority .= '@';
        }

        $ownHost = strcasecmp($parts['host'], $fromDomain) === 0;
        $changed = $changed || $ownHost;
        $host = $ownHost ? $toDomain : $parts['host'];
        $path = $parts['path'] ?? '';
        $database = ltrim($path, '/');

        // A web address's path is a page, not a database — only a connection
        // URL (mysql://, postgresql://, mongodb://…) names one there.
        $connection = ! in_array(strtolower($parts['scheme']), ['http', 'https'], true);

        if ($connection && $database !== '' && ! str_contains($database, '/') && isset($replace[rawurldecode($database)])) {
            $path = '/'.rawurlencode($replace[rawurldecode($database)]);
            $changed = true;
        }

        // Rebuilt only when something in it is the source's: re-encoding a
        // URL that needed no change could still change how it is spelled.
        if (! $changed) {
            return $value;
        }

        return $parts['scheme'].'://'.$authority.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
