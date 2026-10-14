<?php

namespace App\Services\Server\Databases\Imports;

/**
 * Turns an uploaded SQL dump into the file an import loads: decompressed, and
 * with the statements a database's own user cannot run taken out.
 *
 * The import runs as the database's own user, never as the panel's admin
 * account — that is what keeps a dump's `USE other_db` or `\connect other_db`
 * from writing into somebody else's database. The price is that dumps are
 * usually made by an admin and carry statements only an admin may run:
 * ownership, grants, definers. Those are removed here, the same things the
 * panel's own exports leave out (`--no-owner --no-privileges`), so a dump from
 * another server loads instead of failing on its first `ALTER … OWNER TO`.
 *
 * What is refused rather than cleaned is PostgreSQL's client commands. `psql`
 * runs `\! command` as a shell command and has no switch to stop it, so a
 * file containing one is refused outright ({@see ImportRefusedException}).
 * MySQL's client does have the switch (`--binary-mode`), which the engine
 * passes; nothing is scanned for there.
 *
 * Statement-level edits only ever apply to a line that holds one whole
 * statement at the start of a statement — the shape every dump tool writes
 * them in. Data is never rewritten.
 */
class ImportSanitizer
{
    /**
     * MySQL / MariaDB: drop `CREATE DATABASE` and `USE` (the file loads into
     * the database it was imported into, whatever it was dumped from), definer
     * clauses (they name an account that only exists on the source server,
     * and setting one needs SUPER) and the GTID / binary-log settings a
     * GTID-enabled MySQL writes at the top, which also need SUPER.
     */
    public function mysql(string $source, string $target): void
    {
        $this->rewrite($source, $target, function ($in, $out): void {
            $skipping = false;

            while (($line = gzgets($in)) !== false) {
                // A multi-line `SET @@GLOBAL.GTID_PURGED=…` runs to its `;`.
                if ($skipping) {
                    $skipping = ! str_ends_with(rtrim($line), ';');

                    continue;
                }

                if (preg_match('/^\s*(CREATE\s+DATABASE\b|USE\s+)[^;]*;\s*$/i', $line) === 1) {
                    continue;
                }

                if (preg_match('/^\s*SET\s+@@(GLOBAL\.GTID_PURGED|SESSION\.SQL_LOG_BIN|SESSION\.GTID_NEXT)\b/i', $line) === 1) {
                    $skipping = ! str_ends_with(rtrim($line), ';');

                    continue;
                }

                // Only on the lines mysqldump puts definers on — never an
                // INSERT, whose data could contain the same words.
                if (stripos($line, 'DEFINER') !== false && preg_match('#^\s*(/\*!|CREATE\b)#i', $line) === 1) {
                    $line = (string) preg_replace(
                        '/DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@*]+)@(`[^`]*`|\'[^\']*\'|[^\s*]+)\s*/i',
                        '',
                        $line,
                    );
                }

                fwrite($out, $line);
            }
        });
    }

    /**
     * PostgreSQL: drop ownership, grants, role switches and extension comments
     * (all need a role the database's user is not), drop pg_dump's own
     * `\restrict` lines, keep `COPY … FROM stdin` data verbatim — and refuse
     * the file if any other client command appears outside a string, an
     * identifier, a comment or a data block.
     *
     * @throws ImportRefusedException
     */
    public function pgsql(string $source, string $target): void
    {
        $this->rewrite($source, $target, function ($in, $out): void {
            $lexer = new PgsqlLexer;
            $number = 0;

            while (($line = gzgets($in)) !== false) {
                $number++;

                if ($lexer->inCopy()) {
                    $lexer->copyLine($line);
                    fwrite($out, $line);

                    continue;
                }

                if ($lexer->atStatementStart() && $this->droppedPgsqlLine($line)) {
                    continue;
                }

                if (! $lexer->scan($line)) {
                    throw new ImportRefusedException('client_command', $number);
                }

                fwrite($out, $line);
            }

            if ($lexer->inCopy()) {
                // A data block that never ends: a truncated upload. psql would
                // read the rest of the session as data.
                throw new ImportRefusedException('truncated', $number);
            }
        });
    }

    private function droppedPgsqlLine(string $line): bool
    {
        return preg_match('/^\\\\(restrict|unrestrict)\s+\S+\s*$/', $line) === 1
            || preg_match('/^\s*ALTER\s+[^;]*\sOWNER\s+TO\s+[^;]*;\s*$/i', $line) === 1
            || preg_match('/^\s*ALTER\s+DEFAULT\s+PRIVILEGES\s[^;]*;\s*$/i', $line) === 1
            || preg_match('/^\s*(GRANT|REVOKE)\s[^;]*;\s*$/i', $line) === 1
            || preg_match('/^\s*(SET\s+(SESSION\s+AUTHORIZATION|ROLE)|RESET\s+(ROLE|SESSION\s+AUTHORIZATION))\b[^;]*;\s*$/i', $line) === 1
            || preg_match('/^\s*COMMENT\s+ON\s+EXTENSION\s[^;]*;\s*$/i', $line) === 1;
    }

    /**
     * Read `$source` (plain or gzip — gzopen reads both) line by line into a
     * fresh 0600 `$target`. A refusal leaves no half-written target behind.
     *
     * @param  callable(resource, resource): void  $filter
     */
    private function rewrite(string $source, string $target, callable $filter): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');
        @chmod($target, 0600);

        try {
            $filter($in, $out);
        } catch (ImportRefusedException $e) {
            fclose($out);
            @unlink($target);

            throw $e;
        } finally {
            gzclose($in);

            if (is_resource($out)) {
                fclose($out);
            }
        }
    }
}
