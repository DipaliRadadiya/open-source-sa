<?php

namespace App\Services\Server\Databases\Imports;

/**
 * Turns an uploaded SQL dump into the file an import loads: decompressed, and
 * with the statements a database's own user cannot run taken out.
 *
 * The import runs as the database's own user, never as the panel's admin account, so a dump's
 * `USE other_db` or `\connect other_db` cannot write into somebody else's database. Dumps are
 * usually made by an admin and carry statements only an admin may run (ownership, grants,
 * definers); those are removed here, as the panel's own exports leave them out
 * (`--no-owner --no-privileges`).
 *
 * PostgreSQL's client commands are refused rather than cleaned: `psql` runs `\! command` as a
 * shell command and has no switch to stop it ({@see ImportRefusedException}). MySQL's client
 * has one (`--binary-mode`), which the engine passes; nothing is scanned for there.
 *
 * Statement-level edits only apply to a line that holds one whole statement at the start of a
 * statement, the shape every dump tool writes. Data is never rewritten.
 */
class ImportSanitizer
{
    /**
     * MySQL / MariaDB: drop `CREATE DATABASE` and `USE` (the file loads into the target
     * database), definer clauses (the account exists only on the source server and setting one
     * needs SUPER) and the GTID / binary-log settings, which also need SUPER.
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

                // Only the lines mysqldump puts definers on, never an INSERT (data).
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
     * PostgreSQL: drop ownership, grants, role switches and extension comments (they need a
     * role the database's user is not) and pg_dump's `\restrict` lines; keep `COPY … FROM stdin`
     * data verbatim; refuse any other client command outside a string, identifier, comment or
     * data block.
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
                // A data block that never ends: a truncated upload.
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
     * Read `$source` (plain or gzip) line by line into a fresh 0600 `$target`. A refusal leaves
     * no half-written target behind.
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
