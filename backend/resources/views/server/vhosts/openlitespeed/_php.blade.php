{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
{{--
    The site's PHP settings, in their own file, included by the body.

    Its own file so a PHP version or limits change rewrites this and nothing
    else: the vhost, its logs, its rewrite rules and anything the customer added
    all stay untouched.

    ## Why this block is not the whole story

    `phpIniOverride` is what the old panel uses and it is reproduced here so the
    two panels write the same file. It does not do everything it appears to:

      - **`disable_functions` is not honoured from here at all.** PHP reads it
        only from a php.ini — LiteSpeed's own maintainers say so plainly ("it
        must be set in php.ini … we cannot override via the php_admin_value").
        That is the list that stops a web shell reaching `exec` and `system`, so
        a panel that sets it here and nowhere else is enforcing nothing while
        showing the operator a saved setting.
      - `memory_limit` is reported not to take either, repeatedly.

    So the body also points LSPHP at a real `.ini` through `PHP_INI_SCAN_DIR`,
    generated from these same settings by SitePhpIni. Both are written: this
    file for structural parity with the old panel, the ini for the directives
    that only a real ini can carry. They cannot drift, because one source
    produces both.
--}}
phpIniOverride {
@if ($openBasedir === null)
    php_admin_value open_basedir               ""
@else
    php_admin_value open_basedir               "{{ $openBasedir }}"
@endif
    php_admin_value disable_functions          "{{ $disableFunctions }}"
    php_admin_value allow_url_fopen            "{{ $allowUrlFopen ? 'On' : 'Off' }}"
    php_value max_execution_time               "{{ $php['max_execution_time'] }}"
    php_value max_input_time                   "{{ $php['max_input_time'] }}"
    php_value max_input_vars                   "{{ $php['max_input_vars'] }}"
    php_value memory_limit                     "{{ $php['memory_limit'] }}"
    php_value post_max_size                    "{{ $php['post_max_size'] }}"
    php_value upload_max_filesize              "{{ $php['upload_max_filesize'] }}"
    php_value session.gc_maxlifetime           "{{ $php['session_gc_maxlifetime'] }}"
@if (! empty($php['auto_prepend_file']))
    php_value auto_prepend_file                "{{ $php['auto_prepend_file'] }}"
@endif
@if (! empty($php['php_timezone']))
    php_value date.timezone                    "{{ $php['php_timezone'] }}"
@endif
@if (! empty($php['additional_directives']))
{{-- What the customer typed. The old panel calls this `extra_conf` and has
     carried it since October; a migrated site that loses it loses settings
     somebody chose deliberately. Carriage returns stripped so a value pasted
     from Windows does not reach the config with them. --}}
{!! str_replace("\r", '', $php['additional_directives']) !!}
@endif
}
