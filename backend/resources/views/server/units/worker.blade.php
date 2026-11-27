{{-- Managed by the panel. Manual edits are overwritten when the worker is saved. --}}
{{-- A template unit: one file, N instances (sv-worker-{slug}@1 … @N). systemd owns the instances, so
     "3 of 4 running" is answered by the OS, not by a cache. --}}
[Unit]
Description={{ $worker->name }} — {{ $application->domain }} (%i)
After=network.target
Wants=network-online.target
@if ($autoRestart)
{{-- Without a limit a crash loop restarts every 5s forever and buries the cause in the journal;
     failing visibly is a state someone can act on. --}}
{{-- In [Unit], where systemd reads them: under [Service] the key is ignored, so the burst counts
     against systemd's default 10 s window, which restarts 5 s apart never fill. --}}
StartLimitBurst=5
StartLimitIntervalSec=60
@endif

[Service]
Type=simple
User={{ $user }}
Group={{ $user }}
WorkingDirectory={{ $directory }}

{{-- The dash means "if it exists". A worker reads the same environment as its application; the path
     comes from the model so it is literally the file the Environment screen edits. --}}
EnvironmentFile=-{{ $envPath }}
Environment=PATH={{ $path }}

ExecStart={{ $exec }}

{{-- SIGTERM, then wait: `queue:work` finishes the job it holds, then exits; a kill mid-job leaves it
     half-done. --}}
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec={{ $stopWaitSeconds }}

@if ($autoRestart)
{{-- always, not on-failure: a worker that exits 0 because its connection
     dropped has still stopped working. --}}
Restart=always
RestartSec=5
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
