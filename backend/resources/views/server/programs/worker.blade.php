; Managed by the panel. Manual edits are overwritten when the worker is saved.
;
; One program, N processes. supervisor owns the copies via `numprocs`, so the
; panel stores only the number it asked for and "3 of 4 running" stays a
; question supervisord answers rather than one we cache — the same division of
; labour the systemd template units had, in the format the commercial panel and
; every Laravel tutorial already use.
[program:{{ $program }}]
{{-- Two-digit suffix (as Laravel's docs do) so names sort correctly in `supervisorctl status` past
     nine copies. --}}
process_name=%(program_name)s_%(process_num)02d
command={{ $command }}
directory={{ $directory }}
numprocs={{ $processes }}
user={{ $user }}

autostart={{ $autoStart ? 'true' : 'false' }}
autorestart={{ $autoRestart ? 'true' : 'false' }}

{{-- Both, so a worker that forks children takes them down with it; otherwise only the spawned process
     is signalled and a `php artisan` that shelled out is left orphaned, still holding the job. --}}
stopasgroup=true
killasgroup=true

{{-- SIGTERM, then wait: `queue:work` finishes the job it holds, then exits; a kill mid-job leaves it
     half-done.
     Must be >= the worker's own `--max-time`, or supervisor SIGKILLs it partway and the job runs twice. --}}
stopsignal=TERM
stopwaitsecs={{ $stopWaitSeconds }}

{{-- One stream: a separate stderr puts a crash reason in a second file nobody opens. --}}
redirect_stderr=true
stdout_logfile={{ $logFile }}
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
@if ($logLevel)
loglevel={{ $logLevel }}
@endif

{{-- The environment the application itself reads, so the worker has the same credentials as the site
     that queued the job. --}}
environment=PATH="{{ $path }}"
@if ($extraConfig)

; Set by the site owner, appended verbatim — so a directive here wins over
; everything above it.
{{ $extraConfig }}
@endif
