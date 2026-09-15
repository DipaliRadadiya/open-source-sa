{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
[Unit]
Description={{ $application->name }} ({{ $application->domain }})
After=network.target
{{-- The app almost certainly talks to a database. Wants, not Requires: a
     database that is briefly down should slow the app's start, not refuse it
     and leave the site off until someone notices. --}}
Wants=network-online.target

[Service]
Type=simple
User={{ $user }}
Group={{ $user }}
WorkingDirectory={{ $documentRoot }}

{{-- The dash means "if it exists". A site that keeps its configuration in the
     environment rather than a file must still be able to start.

     From the model, not spelled out again here: this unit and the panel's
     Environment screen have to name the same file, and when they each built
     the path themselves they stopped agreeing — the screen edited one `.env`
     while systemd loaded another. --}}
EnvironmentFile=-{{ $envPath }}
Environment=NODE_ENV=production
Environment=PATH={{ $path }}
@if ($clustered)
{{-- PM2 writes its socket, pidfile and state here. Left at its default of
     ~/.pm2 it lands under ProtectHome=read-only and PM2 cannot start at all —
     with an error that names the hardening rather than the directory. A
     sibling of the log dir for the same reason the logs are there: everything
     under the document root is a URL. Per application, so two sites under one
     system user cannot see each other's processes. --}}
Environment=PM2_HOME={{ $pm2Home }}
@endif
@if ($application->app_port)
{{-- The port the panel allocated. The app is expected to read PORT; the
     reverse proxy is pointed at the same number, so the two cannot disagree. --}}
Environment=PORT={{ $application->app_port }}
@endif

ExecStart={{ $exec }}

{{-- Always, not on-failure: a Node process that exits cleanly because of an
     unhandled rejection has still taken the site down. --}}
Restart=always
RestartSec=5
{{-- Without this a crash loop restarts forever at 5s intervals and buries the
     cause in the journal. Five failures in a minute stops the unit and leaves
     it visibly failed, which is the state someone can act on.

     The window is wider under PM2 because the cycle is longer: PM2 retries the
     workers itself and only then exits, so a full failure takes roughly 18s
     rather than the ~5s a bare node process takes. Measured against 60s the
     count never reached five and the unit restarted forever while still
     reporting active — the exact failure this pair exists to prevent. --}}
StartLimitBurst=5
StartLimitIntervalSec={{ $startLimitInterval }}

{{-- One slice per application: per-app CPU and memory accounting comes free,
     and one runaway site cannot starve the others. --}}
Slice={{ $slice }}
MemoryMax={{ $memoryMax }}

{{-- The application is third-party code running with a shell user's rights.
     None of it needs to escalate, write outside its own tree, or see anyone
     else's temporary files. --}}
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
{{-- The log directory sits beside public_html, not under it, so it needs
     naming here in its own right: ProtectHome=read-only makes the rest of
     /home unwritable, and systemd cannot append to a file it cannot write. --}}
ReadWritePaths={{ $documentRoot }} {{ $logDir }}@if ($clustered) {{ $pm2Home }}@endif

{{-- Files in the site's own directory rather than the journal, so the logs
     live with the application they belong to and an operator can reach them
     over SFTP without root. Kept out of public_html: anything under the
     document root is a URL, and an error log is not something to publish.

     systemd holds these files open for the life of the process, so logrotate
     must use copytruncate — see LogRotation, which writes that config. --}}
StandardOutput=append:{{ $logDir }}/app.log
StandardError=append:{{ $logDir }}/app-error.log
SyslogIdentifier=sv-app-{{ $application->id }}

[Install]
WantedBy=multi-user.target
