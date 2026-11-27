{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
[Unit]
Description={{ $application->name }} ({{ $application->domain }})
After=network.target
{{-- The app almost certainly uses a database. Wants, not Requires: a briefly-down database should
     slow the app's start, not leave the site off until someone notices. --}}
Wants=network-online.target
{{-- Without a start limit a crash loop restarts every 5s forever and buries the cause in the
     journal; five failures in the window leave the unit visibly failed.
     The window is a variable: under PM2 a full failure cycle takes ~18s (bare node ~5s), and a window
     that is too short never reaches five. --}}
{{-- In [Unit], where systemd reads them: under [Service] the key is ignored, so the burst counts
     against systemd's default 10 s window, which restarts 5 s apart never fill. --}}
StartLimitBurst=5
StartLimitIntervalSec={{ $startLimitInterval }}

[Service]
Type=simple
User={{ $user }}
Group={{ $user }}
WorkingDirectory={{ $documentRoot }}

{{-- The dash means "if it exists": a site that keeps its configuration in the environment must
     still start. The path comes from the model so the unit and the Environment screen name the same
     file. --}}
EnvironmentFile=-{{ $envPath }}
Environment=NODE_ENV=production
Environment=PATH={{ $path }}
@if ($clustered)
{{-- PM2 writes its socket, pidfile and state here. At its default (~/.pm2) it falls under
     ProtectHome=read-only and PM2 cannot start, with an error that names the hardening, not the
     directory. A sibling of the log dir (under the document root everything is a URL), per
     application so two sites under one system user cannot see each other's processes. --}}
Environment=PM2_HOME={{ $pm2Home }}
@endif
@if ($application->app_port)
{{-- The port the panel allocated; the reverse proxy points at the same number, so the two cannot
     disagree. The app is expected to read PORT. --}}
Environment=PORT={{ $application->app_port }}
@endif

{{-- PORT is also set on the command itself: systemd lets an EnvironmentFile override Environment=
     whatever their order, so a `PORT=` in the app's own .env would win and the proxy would answer 502.
     `env` runs last, so the panel's port is the one the process sees. --}}
ExecStart={{ $application->app_port ? '/usr/bin/env PORT='.$application->app_port.' ' : '' }}{{ $exec }}

{{-- Always, not on-failure: a Node process that exits cleanly (e.g. an unhandled rejection) has still
     taken the site down. --}}
Restart=always
RestartSec=5
{{-- One slice per application: per-app CPU and memory accounting comes free,
     and one runaway site cannot starve the others. --}}
Slice={{ $slice }}
MemoryMax={{ $memoryMax }}

{{-- Third-party code with a shell user's rights: none of it needs to escalate, write outside its own
     tree, or see anyone else's temporary files. --}}
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
{{-- The log directory sits beside public_html, not under it, so it needs naming here in its own
     right: ProtectHome=read-only makes the rest of /home unwritable. --}}
ReadWritePaths={{ $documentRoot }} {{ $logDir }}@if ($clustered) {{ $pm2Home }}@endif

{{-- Files in the site's own directory rather than the journal, so an operator reaches them over SFTP
     without root. Kept out of public_html: anything under the document root is a URL.
     systemd holds these files open for the life of the process, so logrotate must use copytruncate
     (see LogRotation). --}}
StandardOutput=append:{{ $logDir }}/app.log
StandardError=append:{{ $logDir }}/app-error.log
SyslogIdentifier=sv-app-{{ $application->id }}

[Install]
WantedBy=multi-user.target
