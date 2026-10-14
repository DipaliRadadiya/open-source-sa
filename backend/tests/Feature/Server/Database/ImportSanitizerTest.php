<?php

use App\Services\Server\Databases\Imports\ImportRefusedException;
use App\Services\Server\Databases\Imports\ImportSanitizer;

/*
 * What an uploaded dump becomes before an import loads it. The PostgreSQL half
 * is the security boundary: psql runs `\! command` as a shell command, as root
 * here, and has no switch to stop it — so the lexer has to find every client
 * command psql would, and must never be fooled into reading one as inside a
 * string.
 */

function sanitized(string $engine, string $sql, bool $gzip = false): string
{
    $in = tempnam(sys_get_temp_dir(), 'imp-in');
    $out = tempnam(sys_get_temp_dir(), 'imp-out');
    file_put_contents($in, $gzip ? gzencode($sql) : $sql);

    try {
        $engine === 'pgsql'
            ? app(ImportSanitizer::class)->pgsql($in, $out)
            : app(ImportSanitizer::class)->mysql($in, $out);

        return (string) file_get_contents($out);
    } finally {
        @unlink($in);
        @unlink($out);
    }
}

function refusedAt(string $sql): ?int
{
    try {
        sanitized('pgsql', $sql);
    } catch (ImportRefusedException $e) {
        return $e->lineNumber ?? 0;
    }

    return null;
}

describe('MySQL and MariaDB', function () {

    it('loads into the chosen database, whatever the dump was made from', function () {
        $out = sanitized('mysql', "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `shop` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\nUSE `shop`;\nCREATE TABLE t (id int);\n");

        expect($out)->toBe("CREATE TABLE t (id int);\n");
    });

    it('drops definers, which name an account on the source server and need SUPER', function () {
        $out = sanitized('mysql', implode("\n", [
            '/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`admin`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET NEW.a = 1 */;;',
            'CREATE DEFINER=root@localhost PROCEDURE p() SELECT 1;',
        ])."\n");

        expect($out)->not->toContain('DEFINER=')
            ->and($out)->toContain('SQL SECURITY DEFINER')
            ->and($out)->toContain('TRIGGER t BEFORE INSERT');
    });

    it('never rewrites data, even data that looks like a statement', function () {
        $data = "INSERT INTO posts VALUES (1,'DEFINER=`root`@`localhost`'),(2,'USE `other`;');\n";

        expect(sanitized('mysql', $data))->toBe($data);
    });

    it('drops the GTID and binary-log settings, a multi-line one to its end', function () {
        $out = sanitized('mysql', "SET @@SESSION.SQL_LOG_BIN= 0;\nSET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ 'aaa:1-5,\nbbb:1-3';\nCREATE TABLE t (id int);\n");

        expect($out)->toBe("CREATE TABLE t (id int);\n");
    });

    it('reads a gzip upload', function () {
        expect(sanitized('mysql', "CREATE TABLE t (id int);\n", gzip: true))->toBe("CREATE TABLE t (id int);\n");
    });
});

describe('PostgreSQL', function () {

    it('drops what only another role may run, keeps the schema', function () {
        $out = sanitized('pgsql', implode("\n", [
            '\\restrict abc123',
            'SET statement_timeout = 0;',
            'CREATE TABLE public.t (id integer);',
            'ALTER TABLE public.t OWNER TO shop_owner;',
            'GRANT ALL ON TABLE public.t TO reporting;',
            'REVOKE USAGE ON SCHEMA public FROM PUBLIC;',
            'SET SESSION AUTHORIZATION \'someone\';',
            'ALTER DEFAULT PRIVILEGES FOR ROLE x GRANT SELECT ON TABLES TO y;',
            'COMMENT ON EXTENSION plpgsql IS \'PL/pgSQL procedural language\';',
            '\\unrestrict abc123',
        ])."\n");

        expect($out)->toBe("SET statement_timeout = 0;\nCREATE TABLE public.t (id integer);\n");
    });

    it('keeps COPY data verbatim, backslashes and all', function () {
        $sql = "COPY public.t (id, note) FROM stdin;\n1\t\\N\n2\tC:\\\\path \\! not a command\n\\.\nSELECT 1;\n";

        expect(sanitized('pgsql', $sql))->toBe($sql);
    });

    it('refuses a client command at the start of a line', function () {
        expect(refusedAt("CREATE TABLE t (id int);\n\\! id > /tmp/pwned\n"))->toBe(2);
    });

    it('refuses one after a statement on the same line, where psql still runs it', function () {
        expect(refusedAt("SELECT 1; \\! id\n"))->toBe(1)
            ->and(refusedAt("SELECT 1 \\gexec\n"))->toBe(1)
            ->and(refusedAt("\\connect other_db\n"))->toBe(1)
            ->and(refusedAt("\\copy t from '/etc/shadow'\n"))->toBe(1);
    });

    it('allows backslashes inside strings, identifiers, comments and dollar quotes', function () {
        $sql = implode("\n", [
            "INSERT INTO t VALUES ('C:\\path\\! fine');",
            "INSERT INTO t VALUES (E'it\\'s \\\\ fine');",
            'SELECT "odd\\name" FROM t;',
            '-- a comment mentioning \\! is not a command',
            '/* outer /* nested \\! */ still comment \\! */ SELECT 1;',
            'CREATE FUNCTION f() RETURNS text AS $body$',
            "  SELECT '\\! inside a function body';",
            '$body$ LANGUAGE sql;',
            "INSERT INTO t VALUES ('a string that",
            "spans lines \\! still a string');",
        ])."\n";

        expect(sanitized('pgsql', $sql))->toBe($sql);
    });

    /*
     * The lexer reads '…' the standard-conforming way. Each of these ends a
     * string where psql ends it (or earlier), so the command after it is seen.
     */
    it('is not fooled by quoting tricks', function (string $sql) {
        expect(refusedAt($sql))->not->toBeNull();
    })->with([
        'doubled quote' => ["SELECT 'it''s' \\! id\n"],
        'escape string ending in an escaped backslash' => ["SELECT E'x\\\\' \\! id\n"],
        'escaped quote then the real end' => ["SELECT E'x\\'y' ; \\! id\n"],
        'backslash before a plain quote' => ["SELECT 'x\\' \\! id\n"],
        'identifier then dollar is not a dollar quote' => ["SELECT a\$b\$ \\! id\n"],
        'positional parameter is not a dollar quote' => ["SELECT \$1 \\! id\n"],
        'closed block comment' => ["/* c */ \\! id\n"],
        'after a dollar quote closes' => ["SELECT \$q\$ x \$q\$ \\! id\n"],
    ]);

    it('refuses a data block that never ends, a truncated upload', function () {
        expect(refusedAt("COPY public.t (id) FROM stdin;\n1\n2\n"))->not->toBeNull();
    });

    it('reads a gzip upload', function () {
        expect(sanitized('pgsql', "SELECT 1;\n", gzip: true))->toBe("SELECT 1;\n");
    });

    it('leaves no half-written file behind when it refuses', function () {
        $in = tempnam(sys_get_temp_dir(), 'imp-in');
        $out = sys_get_temp_dir().'/imp-out-'.getmypid().'.sql';
        file_put_contents($in, "SELECT 1;\n\\! id\n");

        expect(fn () => app(ImportSanitizer::class)->pgsql($in, $out))->toThrow(ImportRefusedException::class)
            ->and(file_exists($out))->toBeFalse();

        @unlink($in);
    });
});
