# Managed by the control panel; do not edit by hand.
#
# A site's own jail bans an address for that site only, inside the web
# server's config for it — never at the firewall. A firewall ban on 80/443 took
# the address off every site on the server and off the panel itself: three
# wrong WordPress logins locked their owner out of the panel (frontend QA
# FB-K). The work is done by {{ $script }}, which keeps the list per site
# and reloads the web server only after its config test passes.

[Definition]

actionstart = {{ $script }} start <slug>
actionstop = {{ $script }} flush <slug>
actioncheck =
actionban = {{ $script }} ban <slug> <ip>
actionunban = {{ $script }} unban <slug> <ip>

[Init]

slug =
