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
}
