#!/usr/bin/env python3
"""Static guard: no accidental command substitution inside install.sh's heredocs.

Every heredoc in install.sh is **unquoted** (`<<CONF`, not `<<'CONF'`) and has to
stay that way -- the bodies are config files and systemd units full of `${APP_DIR}`,
`${PANEL_SLUG}` and friends, and a quoted delimiter would write those through
literally. The cost of that is that an unquoted body is also a place where the shell
performs *command substitution*, and the bodies are heavily commented prose.

That combination shipped twice, and the failure is a liar:

    # Reads `high` before `default`: still one job at a time, ...

Backticks in a comment are not a comment. The shell ran `high` and `default`,
both exited 127, and because install.sh runs under `set -Eeuo pipefail` with an ERR
trap, the trap fired *inside the subshell* and printed the installer's full
"Install stopped" report -- while the parent shell carried on and the install
actually succeeded. Every single install on every stack ended with two fake fatal
errors. Worse, the trap's own output was substituted into the text, so the systemd
unit written to disk contained:

    # Reads Install stopped ... exit 127 before Install stopped ... exit 127: ...

So the guard is not style policing. An unreviewed backtick in one of these bodies
produces a bogus fatal error, a mangled output file, and an exit status nobody can
trace. Prose in these bodies uses 'single quotes'.

Deliberate substitution is still allowed, but only from the named list below --
install.sh really does splice generated config blocks into the OLS vhosts. Adding a
new one is a one-line edit here, which is the point: it has to be a decision.

Usage:
    python3 tests/install-heredoc-guard.py [FILE ...]   # defaults to install.sh
    python3 tests/install-heredoc-guard.py --self-test  # prove the scanner works

Exit 0 = clean, 1 = finding (or a self-test failure).
"""
import os
import re
import sys

# Shell functions whose output install.sh intentionally splices into a heredoc body.
# Anything else -- `$(cat ...)`, `$(date)`, a stray backtick -- is a finding.
ALLOWED_SUBSTITUTIONS = {
    'ols_acme_context',
    'ols_dotfile_context',
    'ols_front_controller',
    'ols_listener_maps',
    'ols_log_blocks',
}

# <<WORD / <<-WORD / <<'WORD' / <<"WORD" / <<\WORD. A quoted or backslashed
# delimiter disables expansion in the body, which makes that body safe.
OPENER = re.compile(r'<<(-?)\s*(?:"([^"]*)"|\'([^\']*)\'|\\(\w[\w.-]*)|(\w[\w.-]*))')
SUBST = re.compile(r'\$\(\s*([^\s()]+)')


def blank_strings_and_comments(line):
    """Blank out quoted spans and trailing comments, preserving length/offsets.

    Without this, a `<<` inside a string or a comment mentioning a delimiter would
    be read as opening a heredoc and the scanner would lose sync with the file --
    and a scanner that has lost sync reports nothing, which looks like a pass.
    """
    out = list(line)
    i, n, quote = 0, len(line), None
    while i < n:
        c = line[i]
        if quote is None:
            if c == '\\':
                # `<<\WORD` is bash's third way of quoting a heredoc delimiter
                # (identical to `<<'WORD'`). Blanking that one backslash would
                # leave a bare WORD behind, so the scanner would read a safe
                # body as unquoted and then hunt for a terminator that never
                # arrives -- desync, which reports nothing.
                if line[max(0, i - 3):i].lstrip('-').endswith('<<'):
                    i += 1
                    continue
                out[i] = ' '
                if i + 1 < n:
                    out[i + 1] = ' '
                i += 2
                continue
            if c == '#' and (i == 0 or line[i - 1] in ' \t;&|('):
                for j in range(i, n):
                    out[j] = ' '
                break
            if c in '"\'':
                quote = c
                out[i] = ' '
        else:
            if c == quote:
                quote = None
            out[i] = ' '
        i += 1
    return ''.join(out)


def find_openers(line):
    """Yield (delimiter, dashed, quoted) for every heredoc opened on this line."""
    code = blank_strings_and_comments(line)
    for m in OPENER.finditer(code):
        start = m.start()
        # `<<<` is a herestring, not a heredoc. Both neighbours are checked because
        # the regex can anchor on either `<` of a three-`<` run.
        if start > 0 and code[start - 1] == '<':
            continue
        if code[start + 2:start + 3] == '<':
            continue
        dq, sq, esc, bare = m.group(2), m.group(3), m.group(4), m.group(5)
        delim = dq if dq is not None else sq if sq is not None else esc
        yield (delim, m.group(1) == '-', True) if delim is not None \
            else (bare, m.group(1) == '-', False)


def inspect_body(open_line, delim, body):
    """Return findings for one unquoted heredoc body."""
    findings = []
    for lineno, text in body:
        if '`' in text:
            findings.append((lineno, delim, open_line, text,
                             'backtick -- the shell runs this as a command; '
                             "use 'single quotes' in prose"))
            continue
        for m in SUBST.finditer(text):
            name = m.group(1)
            if name not in ALLOWED_SUBSTITUTIONS:
                findings.append((lineno, delim, open_line, text,
                                 f'$({name} ...) is not in ALLOWED_SUBSTITUTIONS'))
    return findings


def scan_lines(lines):
    """Walk a shell script, tracking heredoc state. Returns (findings, error)."""
    findings = []
    pending = []   # heredocs opened on this line, not yet reading their body
    active = None  # (delim, dashed, quoted, open_line, body)
    for lineno, line in enumerate(lines, 1):
        if active is not None:
            delim, dashed, quoted, open_line, body = active
            if (line.lstrip('\t') if dashed else line) == delim:
                if not quoted:
                    findings += inspect_body(open_line, delim, body)
                active = None
                if pending:
                    d, dsh, q = pending.pop(0)
                    active = (d, dsh, q, lineno, [])
            else:
                body.append((lineno, line))
            continue
        pending += list(find_openers(line))
        if pending:
            d, dsh, q = pending.pop(0)
            active = (d, dsh, q, lineno, [])
    if active is not None:
        return findings, f'unterminated heredoc <<{active[0]} opened at line {active[3]}'
    return findings, None


def scan_file(path):
    with open(path, encoding='utf-8') as fh:
        findings, error = scan_lines(fh.read().split('\n'))
    if error:
        print(f'{path}: {error}', file=sys.stderr)
        print('  (the scanner lost sync with the file -- treat as a failure, '
              'not a pass)', file=sys.stderr)
        return 1
    for lineno, delim, open_line, text, why in findings:
        print(f'{path}:{lineno}: {why}')
        print(f'    in body of unquoted <<{delim} (opened at line {open_line})')
        print(f'    {text.strip()}')
    return 1 if findings else 0


SELF_TEST = [
    # (name, script, expected number of findings)
    ('backtick in unquoted body is caught',
     ['cat >f <<UNIT', '# Reads `high` before `default`', 'UNIT'], 1),
    ('backtick in QUOTED body is allowed (no expansion happens there)',
     ["cat >f <<'UNIT'", '# Reads `high` before `default`', 'UNIT'], 0),
    ('backtick in a <<"X" body is allowed',
     ['cat >f <<"UNIT"', '# `nope`', 'UNIT'], 0),
    ('backtick in a <<\\X body is allowed',
     ['cat >f <<\\UNIT', '# `nope`', 'UNIT'], 0),
    ('allowlisted substitution passes',
     ['cat >f <<CONF', '$(ols_log_blocks)', 'CONF'], 0),
    ('unknown substitution is caught',
     ['cat >f <<CONF', '$(whoami)', 'CONF'], 1),
    ('${...} parameter expansion is not a finding',
     ['cat >f <<CONF', 'WorkingDirectory=${backend}', 'CONF'], 0),
    ('<<- strips only tabs when matching the terminator',
     ['cat >f <<-UNIT', '# `bad`', '\tUNIT'], 1),
    ('a herestring is not a heredoc',
     ['cmd <<<"`date`"'], 0),
    ('text after the terminator is outside the body',
     ['cat >f <<UNIT', 'safe', 'UNIT', 'echo "`date`"'], 0),
    ('two heredocs on one line are both tracked',
     ['cmd <<A <<B', '# `one`', 'A', '# `two`', 'B'], 2),
    ('a comment mentioning a delimiter does not open a heredoc',
     ['# see <<UNIT below', 'echo ok'], 0),
    ('an unterminated heredoc is an error, not a pass',
     ['cat >f <<UNIT', '# `bad`'], 'error'),
]


def self_test():
    failures = 0
    for name, script, expected in SELF_TEST:
        findings, error = scan_lines(script)
        if expected == 'error':
            got, ok = ('error' if error else f'{len(findings)} finding(s)'), bool(error)
        else:
            got, ok = f'{len(findings)} finding(s)', (not error and len(findings) == expected)
        print(f'{"PASS" if ok else "FAIL"}  {name}'
              + ('' if ok else f'  -- expected {expected}, got {got}'))
        failures += 0 if ok else 1
    print(f'\nself-test: {len(SELF_TEST) - failures}/{len(SELF_TEST)} passed')
    return 1 if failures else 0


if __name__ == '__main__':
    args = sys.argv[1:]
    if '--self-test' in args:
        sys.exit(self_test())
    if not args:
        here = os.path.dirname(os.path.abspath(__file__))
        args = [os.path.join(os.path.dirname(here), 'install.sh')]
    rc = 0
    for path in args:
        rc |= scan_file(path)
    if rc == 0:
        print(f'install-heredoc-guard: clean ({len(args)} file(s))')
    sys.exit(rc)
