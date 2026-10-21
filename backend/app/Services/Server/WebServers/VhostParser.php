<?php

namespace App\Services\Server\WebServers;

/**
 * Reads the names a vhost answers to and the directory it serves.
 *
 * Moved out of `ApplicationDiscoverer` so that Server Sync, which adopts a site
 * from its config, and deleting that site, which has to decide whether the
 * same config still belongs to it, read the file identically. Two readers
 * that drift apart would let the delete accept a file discovery would never
 * have adopted.
 *
 * `domains`, `root` and `port` are exactly what discovery always returned.
 * `roots` and `unowned_names` are for the delete: a file serving two
 * directories, or a catch-all/wildcard name, is not provably one site's.
 */
class VhostParser
{
    /**
     * Both syntaxes in one pass — nginx `server_name`/`root`, Apache
     * `ServerName`/`ServerAlias`/`DocumentRoot` — because which one is in
     * front is a per-server fact and the file itself says which it is.
     *
     * @return array{domains: array<int, string>, root: string|null, port: int|null, roots: array<int, string>, unowned_names: array<int, string>}
     */
    public function parse(string $contents): array
    {
        $names = [];

        // nginx directives end at `;`, not at a newline, so a whole server
        // block can sit on one line — `server { listen 80; server_name x;
        // root /y; }` — and Sync skipped it (bug #41). A directive starts
        // after a newline, `{` or `;`. Comments are dropped first so that
        // widening cannot pick a commented-out `root` out of one.
        $nginx = (string) preg_replace('/#[^\n]*/', '', $contents);
        $start = '(?:^|[;{])\s*';

        // nginx: `server_name a b c;`
        if (preg_match_all('/'.$start.'server_name\s+([^;]+);/mi', $nginx, $matches)) {
            foreach ($matches[1] as $group) {
                $names = array_merge($names, preg_split('/\s+/', trim($group)) ?: []);
            }
        }

        // Apache: ServerName one, ServerAlias many.
        if (preg_match_all('/^\s*Server(?:Name|Alias)\s+(.+)$/mi', $contents, $matches)) {
            foreach ($matches[1] as $group) {
                $names = array_merge($names, preg_split('/\s+/', trim($group)) ?: []);
            }
        }

        // OpenLiteSpeed: vhDomain and vhAliases, both comma-separated lists
        // rather than space-separated — split on either, since a config
        // written by hand may use spaces after the commas or instead of them.
        if (preg_match_all('/^\s*vh(?:Domain|Aliases)\s+(.+)$/mi', $contents, $matches)) {
            foreach ($matches[1] as $group) {
                $names = array_merge($names, preg_split('/[,\s]+/', trim($group)) ?: []);
            }
        }

        $names = array_values(array_unique(array_filter(
            array_map('strtolower', array_map('trim', $names)),
            fn (string $d): bool => $d !== '',
        )));

        // `_` is nginx's catch-all and names nothing; a wildcard is not a
        // domain the panel can own.
        $owned = fn (string $d): bool => $d !== '_' && ! str_contains($d, '*');
        $domains = array_values(array_filter($names, $owned));
        $unowned = array_values(array_filter($names, fn (string $d): bool => ! $owned($d)));

        $root = null;

        if (preg_match('/'.$start.'root\s+([^;]+);/mi', $nginx, $m)) {
            $root = trim($m[1]);
        } elseif (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $contents, $m)) {
            $root = trim($m[1]);
        } elseif (preg_match('/^\s*docRoot\s+"?([^"\s]+)"?/mi', $contents, $m)) {
            // OpenLiteSpeed. Matched last because `docRoot` is also a legal
            // Apache-ish spelling nobody uses, and nginx/Apache should keep
            // answering for their own files.
            $root = trim($m[1]);
        }

        // Every directory the file serves, not just the first. A `location`
        // with its own `root`, or a second server block for another folder,
        // makes the file something other than one site's config.
        $roots = [];

        foreach ([
            ['/'.$start.'root\s+([^;]+);/mi', $nginx],
            ['/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $contents],
            ['/^\s*docRoot\s+"?([^"\s]+)"?/mi', $contents],
        ] as [$pattern, $subject]) {
            if (preg_match_all($pattern, $subject, $matches)) {
                foreach ($matches[1] as $found) {
                    $roots[] = rtrim(trim($found), '/');
                }
            }
        }

        // The port a Node application is proxied to. Nothing else in discovery
        // needs one — a PHP or static site is served from its root — but a Node
        // site's port is the only thing tying the vhost to the process, and it
        // lives nowhere else the panel can read.
        //
        // Without it an adopted Node application arrives with a null
        // `app_port`, the allocator hands out a fresh one on the first deploy,
        // and nginx goes on proxying to the old number: a site that was
        // working answers 502 the first time the panel touches it. Seen on a
        // real v7 box, where n8n is proxied to 50241.
        //
        // Loopback only. A `proxy_pass` to another host is a reverse proxy to
        // something this server does not run, and claiming its port would be
        // wrong.
        $port = null;

        if (preg_match('#'.$start.'proxy_pass\s+https?://(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)#mi', $nginx, $m)) {
            $port = (int) $m[1];
        }

        return [
            'domains' => $domains,
            'root' => $root,
            'port' => $port,
            'roots' => array_values(array_unique($roots)),
            'unowned_names' => $unowned,
        ];
    }

    /**
     * What else a file does besides naming domains and a root — for the
     * delete, which may only remove a file that is provably one site's.
     *
     * `parse()` answers "which names, which folder" with patterns, which is
     * right for discovery and not enough for a removal: a second `server`
     * block with no name is the catch-all for its port, an `include` brings
     * in whatever another file says, an `alias` serves another folder, and
     * an `upstream` at the top may be used by every other site. None of that
     * changes the domains or the root, so all of it has to be read.
     *
     * Read as the web server reads it — nginx's tokens (quotes, escapes, `#`
     * only at the start of a word, `${var}`), Apache's sections and line
     * continuations. Anything that does not read cleanly lands in `stray`,
     * and a caller treats a non-empty `stray` as "not provable".
     *
     * @param  'nginx'|'apache'  $syntax
     * @return array{blocks: array<int, array{names: array<int, string>, default: bool}>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}
     */
    public function structure(string $contents, string $syntax): array
    {
        $shape = ['blocks' => [], 'includes' => [], 'aliases' => [], 'roots' => [], 'stray' => []];

        return $syntax === 'apache' ? $this->apacheStructure($contents, $shape) : $this->nginxStructure($contents, $shape);
    }

    /**
     * @param  array{blocks: array<int, array{names: array<int, string>, default: bool}>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}  $shape
     * @return array{blocks: array<int, array{names: array<int, string>, default: bool}>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}
     */
    private function nginxStructure(string $contents, array $shape): array
    {
        $tokens = $this->nginxTokens($contents);
        $position = 0;
        $tree = $tokens === null ? null : $this->nginxBlock($tokens, $position, true);

        if ($tree === null) {
            $shape['stray'][] = 'unreadable';

            return $shape;
        }

        // At the top only `server` blocks: an upstream, a map, an include or
        // any other http-level directive is shared with every other file.
        foreach ($tree as $directive) {
            if ($directive['name'] !== 'server' || $directive['children'] === null || $directive['args'] !== []) {
                $shape['stray'][] = 'top:'.$directive['name'];

                continue;
            }

            $block = ['names' => [], 'default' => false, 'named' => false];
            $this->nginxWalk($directive['children'], $block, $shape);
            $shape['blocks'][] = ['names' => $block['names'], 'default' => $block['default'] || ! $block['named']];
        }

        return $shape;
    }

    /**
     * @param  array<int, array{name: string, args: array<int, string>, children: array<int, mixed>|null}>  $directives
     * @param  array{names: array<int, string>, default: bool, named: bool}  $block
     * @param  array{blocks: array<int, mixed>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}  $shape
     */
    private function nginxWalk(array $directives, array &$block, array &$shape): void
    {
        foreach ($directives as $directive) {
            $args = $directive['args'];

            switch ($directive['name']) {
                case 'server_name':
                    $block['names'] = [...$block['names'], ...$args];
                    $block['named'] = $block['named'] || $args !== [];
                    break;
                case 'listen':
                    // `default` is the spelling before nginx 0.8.21.
                    $block['default'] = $block['default'] || array_intersect(['default_server', 'default'], $args) !== [];
                    break;
                case 'include':
                    $shape['includes'] = [...$shape['includes'], ...$args];
                    break;
                case 'alias':
                    $shape['aliases'] = [...$shape['aliases'], ...$args];
                    break;
                case 'root':
                    $shape['roots'] = [...$shape['roots'], ...$args];
                    break;
                case 'server':
                    $shape['stray'][] = 'nested:server';
                    break;
            }

            if ($directive['children'] !== null) {
                $this->nginxWalk($directive['children'], $block, $shape);
            }
        }
    }

    /**
     * nginx's tokenizer (`ngx_conf_read_token`), for the subset a vhost uses:
     * words end at whitespace, `;` or `{` (`}` only ends one at the start);
     * `"`/`'` quote only at the start of a word, with `\` escapes; `#` is a
     * comment only at the start of a word; `${name}` stays inside its word.
     * Null for an unterminated quote or variable.
     *
     * @return array<int, array{0: string, 1?: string}>|null
     */
    private function nginxTokens(string $contents): ?array
    {
        $tokens = [];
        $length = strlen($contents);
        $i = 0;

        while ($i < $length) {
            $char = $contents[$i];

            if (ctype_space($char)) {
                $i++;
            } elseif ($char === '#') {
                $end = strpos($contents, "\n", $i);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === ';' || $char === '{' || $char === '}') {
                $tokens[] = [$char];
                $i++;
            } elseif ($char === '"' || $char === "'") {
                $value = '';
                $i++;

                while ($i < $length && $contents[$i] !== $char) {
                    if ($contents[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }

                    $value .= $contents[$i++];
                }

                if ($i >= $length) {
                    return null;
                }

                $i++;
                $tokens[] = ['word', $value];
            } else {
                $value = '';

                while ($i < $length && ! ctype_space($contents[$i]) && $contents[$i] !== ';' && $contents[$i] !== '{') {
                    if ($contents[$i] === '$' && ($contents[$i + 1] ?? '') === '{') {
                        $end = strpos($contents, '}', $i);

                        if ($end === false) {
                            return null;
                        }

                        $value .= substr($contents, $i, $end - $i + 1);
                        $i = $end + 1;
                    } elseif ($contents[$i] === '\\' && $i + 1 < $length) {
                        $value .= $contents[$i].$contents[$i + 1];
                        $i += 2;
                    } else {
                        $value .= $contents[$i++];
                    }
                }

                $tokens[] = ['word', $value];
            }
        }

        return $tokens;
    }

    /**
     * Directives up to the matching `}` (or the end, at the top). Null when
     * the braces do not balance or a directive is left unterminated.
     *
     * @param  array<int, array{0: string, 1?: string}>  $tokens
     * @return array<int, array{name: string, args: array<int, string>, children: array<int, mixed>|null}>|null
     */
    private function nginxBlock(array $tokens, int &$position, bool $top): ?array
    {
        $directives = [];
        $words = [];

        while ($position < count($tokens)) {
            $token = $tokens[$position++];

            if ($token[0] === 'word') {
                $words[] = $token[1] ?? '';

                continue;
            }

            if ($token[0] === '}') {
                return $top || $words !== [] ? null : $directives;
            }

            if ($words === []) {
                return null;
            }

            $children = null;

            if ($token[0] === '{') {
                $children = $this->nginxBlock($tokens, $position, false);

                if ($children === null) {
                    return null;
                }
            }

            $directives[] = ['name' => strtolower(array_shift($words)), 'args' => $words, 'children' => $children];
            $words = [];
        }

        return $top && $words === [] ? $directives : null;
    }

    /**
     * Apache, section by section. At the top only `<VirtualHost>` and the
     * `<IfModule>` certbot wraps it in; a directive or any other section at
     * the top is server-wide.
     *
     * @param  array{blocks: array<int, array{names: array<int, string>, default: bool}>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}  $shape
     * @return array{blocks: array<int, array{names: array<int, string>, default: bool}>, includes: array<int, string>, aliases: array<int, string>, roots: array<int, string>, stray: array<int, string>}
     */
    private function apacheStructure(string $contents, array $shape): array
    {
        // A line ending in `\` continues on the next.
        $lines = preg_split('/\r?\n/', (string) preg_replace('/\\\\\r?\n/', ' ', $contents)) ?: [];
        $stack = [];
        $block = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('#^</\s*([A-Za-z]+)\s*>$#', $line, $m)) {
                if ($stack === [] || strcasecmp(array_pop($stack), $m[1]) !== 0) {
                    $shape['stray'][] = 'unbalanced';

                    return $shape;
                }

                if (strcasecmp($m[1], 'VirtualHost') === 0 && $block !== null) {
                    $shape['blocks'][] = ['names' => $block['names'], 'default' => $block['default'] || ! $block['named']];
                    $block = null;
                }

                continue;
            }

            if (preg_match('#^<\s*([A-Za-z]+)(.*)>$#', $line, $m)) {
                $section = $m[1];
                $args = $this->apacheArgs(trim($m[2]));

                if (strcasecmp($section, 'VirtualHost') === 0) {
                    if ($block !== null) {
                        $shape['stray'][] = 'nested:virtualhost';
                    }

                    // `_default_` is the catch-all, the way nginx's
                    // `default_server` is.
                    $block = ['names' => [], 'named' => false, 'default' => array_filter($args, fn (string $a): bool => stripos($a, '_default_') === 0) !== []];
                } elseif ($block === null && strcasecmp($section, 'IfModule') !== 0) {
                    $shape['stray'][] = 'top:<'.strtolower($section).'>';
                } elseif (strcasecmp($section, 'Macro') === 0) {
                    $shape['stray'][] = 'macro';
                }

                $stack[] = $section;

                continue;
            }

            $words = $this->apacheArgs($line);
            $name = strtolower((string) array_shift($words));

            if ($block === null) {
                $shape['stray'][] = 'top:'.$name;

                continue;
            }

            switch ($name) {
                case 'servername':
                    $block['names'] = [...$block['names'], ...$words];
                    $block['named'] = $block['named'] || $words !== [];
                    break;
                case 'serveralias':
                    $block['names'] = [...$block['names'], ...$words];
                    break;
                case 'include':
                case 'includeoptional':
                    $shape['includes'] = [...$shape['includes'], ...$words];
                    break;
                case 'alias':
                case 'aliasmatch':
                case 'scriptalias':
                case 'scriptaliasmatch':
                    // The folder is the last argument: `Alias /url /dir`.
                    $shape['aliases'][] = (string) end($words);
                    break;
                case 'documentroot':
                    $shape['roots'] = [...$shape['roots'], ...$words];
                    break;
                case 'use':
                    // A macro expands to whatever its definition says, elsewhere.
                    $shape['stray'][] = 'macro';
                    break;
            }
        }

        if ($stack !== [] || $block !== null) {
            $shape['stray'][] = 'unbalanced';
        }

        return $shape;
    }

    /** @return array<int, string> words, a quoted one as one word without its quotes */
    private function apacheArgs(string $text): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): string => ($m[2] ?? '') !== '' ? $m[2] : stripcslashes($m[1]), $matches);
    }
}
