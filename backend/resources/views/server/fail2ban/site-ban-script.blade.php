#!/bin/bash
# Managed by the control panel; do not edit by hand.
#
# Called by fail2ban (action panel-site-ban) to ban or unban an address for ONE
# site, inside the {{ $webServer }} config for that site — never at the firewall,
# so the panel and every other site stay reachable for that address.
#
#   {{ $self }} start|flush <slug>
#   {{ $self }} ban|unban <slug> <ip>
#
# The banned addresses are kept one per line in <rules dir>/.panel-fail2ban.ips
# and rendered into <rules dir>/panel-fail2ban.conf, which the site's vhost
# already includes. The web server is reloaded only when the rendered file
# changed, and only after its own config test passes; a failed test puts the
# previous file back.

set -u

action="${1:-}"
slug="${2:-}"
ip="${3:-}"

case "$slug" in
    ''|*[!a-z0-9._-]*|.*) echo "panel-site-ban: bad site name" >&2; exit 2 ;;
esac

case "$action" in
    ban|unban)
        case "$ip" in
            ''|*[!0-9a-fA-F:.]*) echo "panel-site-ban: bad address" >&2; exit 2 ;;
        esac
        ;;
    start|flush) ;;
    *) echo "panel-site-ban: unknown action" >&2; exit 2 ;;
esac

dir={!! $rulesRootQuoted !!}"/$slug"
list="$dir/.panel-fail2ban.ips"
out="$dir/panel-fail2ban.conf"

mkdir -p "$dir"
touch "$list"

case "$action" in
    ban)
        grep -qxF "$ip" "$list" || echo "$ip" >> "$list"
        ;;
    unban)
        grep -vxF "$ip" "$list" > "$list.new"
        mv -f "$list.new" "$list"
        ;;
    flush)
        : > "$list"
        ;;
esac

render() {
    [ -s "$list" ] || return 0
    echo "# Managed by the control panel (fail2ban, site $slug); do not edit by hand."
@if ($webServer === 'nginx')
    while IFS= read -r a; do
        [ -n "$a" ] && echo "deny $a;"
    done < "$list"
@elseif ($webServer === 'apache')
    while IFS= read -r a; do
        [ -n "$a" ] && echo "SetEnvIfExpr \"-R '$a'\" panel_banned"
    done < "$list"
    echo "<If \"-n reqenv('panel_banned')\">"
    echo "    Require all denied"
    echo "</If>"
@else
    first=1
    while IFS= read -r a; do
        [ -n "$a" ] || continue
        [ $first -eq 1 ] || echo " [OR]"
        printf 'RewriteCond %%{REMOTE_ADDR} =%s' "$a"
        first=0
    done < "$list"
    echo
    echo "RewriteRule ^ - [F,L]"
@endif
}

render > "$out.new"

if [ -f "$out" ] && cmp -s "$out" "$out.new"; then
    rm -f "$out.new"
    exit 0
fi

[ -f "$out" ] && cp -p "$out" "$out.prev"
mv -f "$out.new" "$out"
chmod 0644 "$out"

if {!! $test !!} >/dev/null 2>&1; then
    rm -f "$out.prev"
    {!! $reload !!}
else
    if [ -f "$out.prev" ]; then mv -f "$out.prev" "$out"; else rm -f "$out"; fi
    echo "panel-site-ban: web server config test failed, ban not applied" >&2
    exit 1
fi
