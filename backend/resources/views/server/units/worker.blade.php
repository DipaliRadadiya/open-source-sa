{{-- Managed by the panel. Manual edits are overwritten when the worker is saved. --}}
{{-- A template unit: one file, N instances (sv-worker-{slug}@1 … @N). systemd
     owns the instances so the panel stores only the number it asked for, and
     "3 of 4 running" stays a question the OS answers rather than one we cache. --}}
[Unit]
Description={{ $worker->name }} — {{ $application->domain }} (%i)
After=network.target
Wants=network-online.target

[Service]
Type=simple
User={{ $user }}
Group={{ $user }}
WorkingDirectory={{ $directory }}

{{-- The dash means "if it exists". A worker reads the same environment as the
     application it belongs to — a queue worker with different credentials from
     the site that queued the job is a long afternoon.

     Which means literally the same file the Environment screen edits, so it
     comes from the model rather than being rebuilt from the project root
     here. Three copies of this path had already drifted apart. --}}
EnvironmentFile=-{{ $envPath }}
Environment=PATH={{ $path }}

ExecStart={{ $exec }}

{{-- SIGTERM, then wait. `queue:work` treats SIGTERM as "finish the job you are
     holding, then exit" — killing it mid-job leaves that job half-done, which
     for anything touching money is the failure that actually matters. --}}
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec={{ $stopWaitSeconds }}

@if ($autoRestart)
{{-- always, not on-failure: a worker that exits 0 because its connection
     dropped has still stopped working. --}}
Restart=always
RestartSec=5
{{-- Without a limit a crash loop restarts forever at 5s intervals and buries
     the cause in the journal. Failing visibly is a state someone can act on. --}}
StartLimitBurst=5
StartLimitIntervalSec=60
@else
Restart=no
@endif

{{-- One slice per application, shared with its web process: per-app CPU and
     memory accounting stays correct, and one runaway worker cannot starve the
     other sites on the box. --}}
Slice=sv-app-{{ $application->id }}.slice

{{-- Third-party code running as a shell user. None of it needs to escalate,
     write outside its own tree, or see anyone else's temporary files. --}}
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
ReadWritePaths={{ $projectRoot }}

StandardOutput=journal
StandardError=journal
SyslogIdentifier=sv-worker-{{ $worker->slug }}

[Install]
WantedBy=multi-user.target
