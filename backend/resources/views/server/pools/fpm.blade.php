; Managed by the panel. Manual edits are overwritten when the site's PHP
; settings are saved — use the Additional directives field instead.
;
; The pool name comes from the application id, never the domain: two files
; declaring the same [name] is undefined behaviour in PHP-FPM, and domains
; change.
[{{ $pool }}]

; The whole point. Without these the site runs as www-data, alongside every
; other site on the box, and the per-site system users protect nothing at the
; point where it counts.
user = {{ $user }}
group = {{ $user }}

; One socket per pool. Two pools on one path is not an error — the second to
; start simply wins, and the first pool's workers become unreachable with
; nothing in any log to say so.
listen = {{ $socket }}
; The web server has to be able to connect; the isolation is the user above.
listen.owner = {{ $webServerUser }}
listen.group = {{ $webServerUser }}
listen.mode = 0660

pm = {{ $pmType }}
pm.max_children = {{ $pmMaxChildren }}
@if ($pmType === 'dynamic')
{{-- Derived rather than asked for: four more numbers that have to agree with
     max_children, and disagreeing is how a pool refuses to start. --}}
pm.start_servers = {{ $pmStartServers }}
pm.min_spare_servers = {{ $pmMinSpare }}
pm.max_spare_servers = {{ $pmMaxSpare }}
@elseif ($pmType === 'ondemand')
pm.process_idle_timeout = 10s
@endif
; Recycles a worker after this many requests, so a slow leak is bounded to one
; worker's lifetime instead of growing until the kernel kills something.
pm.max_requests = {{ $pmMaxRequests }}

; Sessions must live inside the site. PHP's default is /var/lib/php/sessions,
; owned by www-data — the moment a site stops being www-data it cannot write
; its own sessions, and every login on the site breaks with no obvious cause.
php_admin_value[session.save_path] = {{ $sessionPath }}

php_admin_value[memory_limit] = {{ $memoryLimit }}
php_admin_value[upload_max_filesize] = {{ $uploadMaxFilesize }}
php_admin_value[post_max_size] = {{ $postMaxSize }}
php_admin_value[max_execution_time] = {{ $maxExecutionTime }}
php_admin_value[max_input_time] = {{ $maxInputTime }}
php_admin_value[max_input_vars] = {{ $maxInputVars }}
php_admin_value[session.gc_maxlifetime] = {{ $sessionGcMaxlifetime }}
@if ($phpTimezone)
php_admin_value[date.timezone] = {{ $phpTimezone }}
@endif
@if ($autoPrependFile)
php_admin_value[auto_prepend_file] = {{ $autoPrependFile }}
@endif
php_admin_flag[allow_url_fopen] = {{ $allowUrlFopen ? 'on' : 'off' }}
@if ($openBasedir)
{{-- A second layer, not the boundary: open_basedir is enforced inside PHP, so
     it stops a script wandering the filesystem but does not stop anything the
     kernel would allow. The user above is what actually isolates the site.
     The session path has to be listed or sessions stop working. --}}
php_admin_value[open_basedir] = {{ $openBasedir }}
@endif
@if ($disableFunctions)
php_admin_value[disable_functions] = {{ $disableFunctions }}
@endif

; Errors go to the site's own log rather than the shared one, so a developer
; can be given their errors without being given everyone else's.
php_admin_value[error_log] = {{ $errorLog }}
php_admin_flag[log_errors] = on

@if ($additionalDirectives)
; ---- Additional directives (set in the panel) ----
{!! $additionalDirectives !!}
@endif
