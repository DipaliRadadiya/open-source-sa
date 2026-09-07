; Managed by the panel. Manual edits are overwritten when the worker is saved.
;
; One program, N processes. supervisor owns the copies via `numprocs`, so the
; panel stores only the number it asked for and "3 of 4 running" stays a
; question supervisord answers rather than one we cache — the same division of
; labour the systemd template units had, in the format the commercial panel and
; every Laravel tutorial already use.
[program:{{ $program }}]
{{-- Two-digit suffix, as Laravel's own documented example does: the process
     names sort correctly in `supervisorctl status` past nine copies. --}}
process_name=%(program_name)s_%(process_num)02d
command={{ $command }}
directory={{ $directory }}
numprocs={{ $processes }}
user={{ $user }}

autostart={{ $autoStart ? 'true' : 'false' }}
autorestart={{ $autoRestart ? 'true' : 'false' }}

{{-- Both, so a worker that forks children takes them with it. Without these
     supervisor signals only the process it spawned, and a `php artisan` that
     shelled out leaves the real work orphaned and still holding the job. --}}
stopasgroup=true
killasgroup=true

{{-- SIGTERM, then wait. `queue:work` treats it as "finish the job you are
     holding, then exit"; being killed mid-job leaves that job half-done, which
     for anything touching money is the failure that actually matters.

     ⚠️ This must be >= the worker's own `--max-time`, or supervisor SIGKILLs
     it partway through and the job runs twice. --}}
stopsignal=TERM
stopwaitsecs={{ $stopWaitSeconds }}

{{-- One stream. Keeping stderr separate means a crash reason lands in a second
     file nobody opens, while the log the panel shows says nothing. --}}
redirect_stderr=true
stdout_logfile={{ $logFile }}
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
@if ($logLevel)
loglevel={{ $logLevel }}
@endif

{{-- The environment the application itself reads. A queue worker with
     different credentials from the site that queued the job is a long
     afternoon. --}}
environment=PATH="{{ $path }}"
@if ($extraConfig)

; Set by the site owner, appended verbatim — so a directive here wins over
; everything above it.
{{ $extraConfig }}
@endif
