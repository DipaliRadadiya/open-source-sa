<?php

namespace App\Services\Server\Databases\Imports;

/**
 * Just enough of `psql`'s own reading of a script to find its client
 * commands: a backslash outside a string, a quoted identifier, a comment or a
 * `COPY … FROM stdin` data block. `psql` runs those itself — `\! command` is a
 * shell command — and accepts them anywhere on a line, not only at its start.
 *
 * Fed one line at a time, carrying its state across lines, so a gigabyte dump
 * never has to be in memory.
 *
 * Strings are read the standard-conforming way (backslash is an ordinary
 * character in '…', an escape only in E'…'). A dump that turns that setting
 * off can only make psql read a string as running *longer* than this does,
 * never shorter — so anything psql would see outside a string, this sees
 * outside one too. It errs towards refusing.
 */
class PgsqlLexer
{
    private const NORMAL = 0;

    private const QUOTED = 1;

    private const ESCAPED = 2;

    private const IDENTIFIER = 3;

    private const DOLLAR = 4;

    private const COMMENT = 5;

    private int $state = self::NORMAL;

    private string $tag = '';

    private int $depth = 0;

    private bool $started = false;

    private bool $copy = false;

    public function inCopy(): bool
    {
        return $this->copy;
    }

    /** Outside every quote and comment, with nothing of a statement read yet. */
    public function atStatementStart(): bool
    {
        return $this->state === self::NORMAL && ! $this->started;
    }

    /** One line of a COPY data block; `\.` on its own ends it. */
    public function copyLine(string $line): void
    {
        if (rtrim($line, "\r\n") === '\\.') {
            $this->copy = false;
        }
    }

    /**
     * Read one line. False when it holds a client command.
     */
    public function scan(string $line): bool
    {
        // pg_dump writes a data block as one statement on one line, then rows.
        if ($this->atStatementStart() && preg_match('/^\s*COPY\s[^;]*\sFROM\s+stdin\b[^;]*;\s*$/i', $line) === 1) {
            $this->copy = true;

            return true;
        }

        $i = 0;
        $n = strlen($line);

        while ($i < $n) {
            switch ($this->state) {
                case self::NORMAL:
                    $run = strcspn($line, "'\"\$-/\\;", $i);

                    if ($run > 0) {
                        if (trim(substr($line, $i, $run)) !== '') {
                            $this->started = true;
                        }
                        $i += $run;

                        break;
                    }

                    $char = $line[$i];
                    $next = $line[$i + 1] ?? '';

                    if ($char === '\\') {
                        return false;
                    }

                    if ($char === ';') {
                        $this->started = false;
                        $i++;

                        break;
                    }

                    if ($char === '-' && $next === '-') {
                        // The rest of the line is a comment.
                        return true;
                    }

                    if ($char === '/' && $next === '*') {
                        $this->state = self::COMMENT;
                        $this->depth = 1;
                        $i += 2;

                        break;
                    }

                    $this->started = true;

                    if ($char === "'") {
                        $this->state = $this->isEscapeStringPrefix($line, $i) ? self::ESCAPED : self::QUOTED;
                        $i++;
                    } elseif ($char === '"') {
                        $this->state = self::IDENTIFIER;
                        $i++;
                    } elseif ($char === '$' && ! $this->followsIdentifier($line, $i)
                        && preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $line, $match, 0, $i) === 1) {
                        $this->state = self::DOLLAR;
                        $this->tag = $match[0];
                        $i += strlen($match[0]);
                    } else {
                        $i++;
                    }

                    break;

                case self::QUOTED:
                case self::IDENTIFIER:
                    $quote = $this->state === self::QUOTED ? "'" : '"';
                    $at = strpos($line, $quote, $i);

                    if ($at === false) {
                        $i = $n;
                    } elseif (($line[$at + 1] ?? '') === $quote) {
                        $i = $at + 2;
                    } else {
                        $this->state = self::NORMAL;
                        $i = $at + 1;
                    }

                    break;

                case self::ESCAPED:
                    $at = $i + strcspn($line, "'\\", $i);

                    if ($at >= $n) {
                        $i = $n;
                    } elseif ($line[$at] === '\\') {
                        $i = $at + 2;
                    } elseif (($line[$at + 1] ?? '') === "'") {
                        $i = $at + 2;
                    } else {
                        $this->state = self::NORMAL;
                        $i = $at + 1;
                    }

                    break;

                case self::DOLLAR:
                    $at = strpos($line, $this->tag, $i);

                    if ($at === false) {
                        $i = $n;
                    } else {
                        $this->state = self::NORMAL;
                        $i = $at + strlen($this->tag);
                    }

                    break;

                case self::COMMENT:
                    // PostgreSQL block comments nest.
                    $open = strpos($line, '/*', $i);
                    $close = strpos($line, '*/', $i);

                    if ($close === false && $open === false) {
                        $i = $n;
                    } elseif ($open !== false && ($close === false || $open < $close)) {
                        $this->depth++;
                        $i = $open + 2;
                    } else {
                        $this->depth--;
                        $i = $close + 2;

                        if ($this->depth === 0) {
                            $this->state = self::NORMAL;
                        }
                    }

                    break;
            }
        }

        return true;
    }

    /** `E'…'` / `e'…'`, but not the end of an identifier such as `name'`. */
    private function isEscapeStringPrefix(string $line, int $quote): bool
    {
        $prefix = $line[$quote - 1] ?? '';

        return ($prefix === 'E' || $prefix === 'e')
            && ($quote < 2 || preg_match('/[A-Za-z0-9_\x80-\xff$]/', $line[$quote - 2]) !== 1);
    }

    /** `a$b$` is one identifier, not the start of a dollar quote. */
    private function followsIdentifier(string $line, int $dollar): bool
    {
        return $dollar > 0 && preg_match('/[A-Za-z0-9_\x80-\xff]/', $line[$dollar - 1]) === 1;
    }
}
