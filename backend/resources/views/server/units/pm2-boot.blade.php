{{-- Managed by the panel. Manual edits are overwritten on the next handover. --}}
[Unit]
Description=PM2 process manager for {{ $user }}
Documentation=https://pm2.keymetrics.io/
After=network.target

[Service]
Type=forking
User={{ $user }}
Group={{ $user }}

{{-- The whole reason this file exists rather than `pm2 startup`. That command
     prints a sudo line for someone to copy and paste, and the old panel
     scraped it with a regex that hardcoded `/usr/lib/node_modules/pm2/bin/pm2`
     and `-u \w+`. A Node upgrade via `n` moves the toolchain to /usr/local and
     a username with a hyphen breaks the pattern; either way the match came
     back empty, the empty string was executed as a command, `bash -c ""`
     exited 0, and the panel reported boot persistence it had never set up.
     Written directly, from values we already hold. --}}
Environment=PM2_HOME={{ $pm2Home }}
PIDFile={{ $pm2Home }}/pm2.pid

{{-- `resurrect` reads the dump this user's applications are saved to. For an
     adopted application that dump is the boot mechanism, which is why every
     state change in LegacyPm2Driver saves it. --}}
ExecStart={{ $pm2 }} resurrect
ExecReload={{ $pm2 }} reload all
ExecStop={{ $pm2 }} kill

Restart=on-failure
RestartSec=5

{{-- PM2's daemon opens a file descriptor per managed process and per log; the
     default soft limit is low enough to matter on an account with many sites. --}}
LimitNOFILE=infinity

[Install]
WantedBy=multi-user.target
