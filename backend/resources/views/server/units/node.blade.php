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
     environment rather than a file must still be able to start. --}}
EnvironmentFile=-{{ $documentRoot }}/.env
Environment=NODE_ENV=production
Environment=PATH={{ $path }}
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
     it visibly failed, which is the state someone can act on. --}}
StartLimitBurst=5
StartLimitIntervalSec=60

{{-- One slice per application: per-app CPU and memory accounting comes free,
     and one runaway site cannot starve the others. --}}
Slice=sv-app-{{ $application->id }}.slice
MemoryMax={{ $memoryMax }}

{{-- The application is third-party code running with a shell user's rights.
     None of it needs to escalate, write outside its own tree, or see anyone
     else's temporary files. --}}
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
ReadWritePaths={{ $documentRoot }}

StandardOutput=journal
StandardError=journal
SyslogIdentifier=sv-app-{{ $application->id }}

[Install]
WantedBy=multi-user.target
