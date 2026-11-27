{{-- Managed by the panel. Manual edits are overwritten on the next handover. --}}
[Unit]
Description=PM2 process manager for {{ $user }}
Documentation=https://pm2.keymetrics.io/
After=network.target

[Service]
Type=forking
User={{ $user }}
Group={{ $user }}

{{-- The reason this file exists instead of `pm2 startup`: that command prints a sudo line to copy,
     and scraping it with a regex broke on Node upgrades and hyphenated usernames, ran an empty command
     and reported boot persistence that was never set up. Written directly from values we hold. --}}
Environment=PM2_HOME={{ $pm2Home }}
PIDFile={{ $pm2Home }}/pm2.pid

{{-- `resurrect` reads the dump this user's applications are saved to. For an adopted application that
     dump is the boot mechanism, so every state change in LegacyPm2Driver saves it. --}}
ExecStart={{ $pm2 }} resurrect
ExecReload={{ $pm2 }} reload all
ExecStop={{ $pm2 }} kill

Restart=on-failure
RestartSec=5

{{-- PM2's daemon opens a file descriptor per managed process and per log; the default soft limit is
     low for an account with many sites. --}}
LimitNOFILE=infinity

[Install]
WantedBy=multi-user.target
